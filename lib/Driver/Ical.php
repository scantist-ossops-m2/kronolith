<?php
/**
 * The Kronolith_Driver_Ical class implements the Kronolith_Driver API for
 * iCalendar data.
 *
 * Possible driver parameters:
 * - url:      The location of the remote calendar.
 * - proxy:    A hash with HTTP proxy information.
 * - user:     The user name for HTTP Basic Authentication.
 * - password: The password for HTTP Basic Authentication.
 *
 * Copyright 2004-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @todo Replace session cache
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 */
class Kronolith_Driver_Ical extends Kronolith_Driver
{
    /**
     * Cache events as we fetch them to avoid fetching or parsing the same
     * event twice.
     *
     * @var array
     */
    private $_cache = array();

    /**
     * Returns the background color of the current calendar.
     *
     * @return string  The calendar color.
     */
    public function backgroundColor()
    {
        foreach ($GLOBALS['all_remote_calendars'] as $calendar) {
            if ($calendar['url'] == $this->calendar) {
                return empty($calendar['color'])
                    ? '#dddddd'
                    : $calendar['color'];
            }
        }
        return '#dddddd';
    }

    public function listAlarms($date, $fullevent = false)
    {
        return array();
    }

    /**
     * Lists all events in the time range, optionally restricting results to
     * only events with alarms.
     *
     * @param Horde_Date $startInterval  Start of range date object.
     * @param Horde_Date $endInterval    End of range data object.
     * @param boolean $showRecurrence    Return every instance of a recurring
     *                                   event? If false, will only return
     *                                   recurring events once inside the
     *                                   $startDate - $endDate range.
     * @param boolean $hasAlarm          Only return events with alarms?
     * @param boolean $json              Store the results of the events'
     *                                   toJson() method?
     * @param boolean $coverDates        Whether to add the events to all days
     *                                   that they cover.
     *
     * @return array  Events in the given time range.
     * @throws Kronolith_Exception
     */
    public function listEvents($startDate = null, $endDate = null,
                               $showRecurrence = false, $hasAlarm = false,
                               $json = false, $coverDates = true)
    {
        $iCal = $this->getRemoteCalendar();

        if (is_null($startDate)) {
            $startDate = new Horde_Date(array('mday' => 1,
                                              'month' => 1,
                                              'year' => 0000));
        }
        if (is_null($endDate)) {
            $endDate = new Horde_Date(array('mday' => 31,
                                            'month' => 12,
                                            'year' => 9999));
        }

        $startDate = clone $startDate;
        $startDate->hour = $startDate->min = $startDate->sec = 0;
        $endDate = clone $endDate;
        $endDate->hour = 23;
        $endDate->min = $endDate->sec = 59;

        $components = $iCal->getComponents();
        $events = array();
        $count = count($components);
        $exceptions = array();
        for ($i = 0; $i < $count; $i++) {
            $component = $components[$i];
            if ($component->getType() == 'vEvent') {
                $event = new Kronolith_Event_Ical($this);
                $event->status = Kronolith::STATUS_FREE;
                $event->fromiCalendar($component);
                // Force string so JSON encoding is consistent across drivers.
                $event->id = 'ical' . $i;

                /* Catch RECURRENCE-ID attributes which mark single recurrence
                 * instances. */
                $recurrence_id = $component->getAttribute('RECURRENCE-ID');
                if (is_int($recurrence_id) &&
                    is_string($uid = $component->getAttribute('UID')) &&
                    is_int($seq = $component->getAttribute('SEQUENCE'))) {
                    $exceptions[$uid][$seq] = $recurrence_id;
                }

                /* Ignore events out of the period. */
                if (
                    /* Starts after the period. */
                    $event->start->compareDateTime($endDate) > 0 ||
                    /* End before the period and doesn't recur. */
                    (!$event->recurs() &&
                     $event->end->compareDateTime($startDate) < 0) ||
                    /* Recurs and ... */
                    ($event->recurs() &&
                      /* ... has a recurrence end before the period. */
                      ($event->recurrence->hasRecurEnd() &&
                       $event->recurrence->recurEnd->compareDateTime($startDate) < 0))) {
                    continue;
                }

                $events[] = $event;
            }
        }

        /* Loop through all explicitly defined recurrence intances and create
         * exceptions for those in the event with the matchin recurrence. */
        $results = array();
        foreach ($events as $key => $event) {
            if ($event->recurs() &&
                isset($exceptions[$event->uid][$event->sequence])) {
                $timestamp = $exceptions[$event->uid][$event->sequence];
                $events[$key]->recurrence->addException(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
            }
            Kronolith::addEvents($results, $event, $startDate, $endDate,
                                 $showRecurrence, $json, $coverDates);
        }

        return $results;
    }

    /**
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     */
    public function getEvent($eventId = null)
    {
        if (!$eventId) {
            return new Kronolith_Event_Ical($this);
        }
        $eventId = str_replace('ical', '', $eventId);
        $iCal = $this->getRemoteCalendar();

        $components = $iCal->getComponents();
        if (isset($components[$eventId]) &&
            $components[$eventId]->getType() == 'vEvent') {
            $event = new Kronolith_Event_Ical($this);
            $event->status = Kronolith::STATUS_FREE;
            $event->fromiCalendar($components[$eventId]);
            $event->id = 'ical' . $eventId;
            return $event;
        }

        throw new Horde_Exception_NotFound(_("Event not found"));
    }

    /**
     * Fetches a remote calendar into the session and return the data.
     *
     * @param boolean $cache  Whether to return data from the session cache.
     *
     * @return Horde_iCalendar  The calendar data, or an error on failure.
     * @throws Kronolith_Exception
     */
    public function getRemoteCalendar($cache = true)
    {
        $url = trim($this->calendar);

        /* Treat webcal:// URLs as http://. */
        if (substr($url, 0, 9) == 'webcal://') {
            $url = str_replace('webcal://', 'http://', $url);
        }

        $signature = $url . '|' . serialize($this->_params);
        if ($cache && !empty($_SESSION['kronolith']['remote'][$signature])) {
            if (!is_object($_SESSION['kronolith']['remote'][$signature])) {
                throw new Kronolith_Exception($_SESSION['kronolith']['remote'][$signature]);
            }
            return $_SESSION['kronolith']['remote'][$signature];
        }

        $options = array('request.timeout' => isset($this->_params['timeout'])
                                              ? $this->_params['timeout']
                                              : 5);
        if (!empty($this->_params['user'])) {
            $options['request.username'] = $this->_params['user'];
            $options['request.password'] = $this->_params['password'];
        }
        if (isset($this->_params['proxy'])) {
            $options['request.proxyServer'] = $this->_params['proxy']['proxy_host'];
            $options['request.proxyPort'] = $this->_params['proxy']['proxy_port'];
            $options['request.proxyUser'] = $this->_params['proxy']['proxy_user'];
            $options['request.proxyPass'] = $this->_params['proxy']['proxy_pass'];
        }

        $http = new Horde_Http_Client($options);
        try {
            $response = $http->get($url);
        } catch (Horde_Http_Exception $e) {
            Horde::logMessage($e, __FILE__, __LINE__, PEAR_LOG_INFO);
            $_SESSION['kronolith']['remote'][$signature] = $e->getMessage();
            throw new Kronolith_Exception($e);
        }
        if ($response->code != 200) {
            Horde::logMessage(sprintf('Failed to retrieve remote calendar: url = "%s", status = %s',
                                      $url, $response->code),
                              __FILE__, __LINE__, PEAR_LOG_INFO);
            $_SESSION['kronolith']['remote'][$signature] = sprintf(_("Could not open %s."), $url);
            throw new Kronolith_Exception($_SESSION['kronolith']['remote'][$signature], $response->code);
        }

        /* Log fetch at DEBUG level. */
        Horde::logMessage(sprintf('Retrieved remote calendar for %s: url = "%s"',
                                  Horde_Auth::getAuth(), $url),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        $data = $response->getBody();
        $_SESSION['kronolith']['remote'][$signature] = new Horde_iCalendar();
        $result = $_SESSION['kronolith']['remote'][$signature]->parsevCalendar($data);
        if ($result instanceof PEAR_Error) {
            throw new Kronolith_Exception($result);
        }

        return $_SESSION['kronolith']['remote'][$signature];
    }

}
