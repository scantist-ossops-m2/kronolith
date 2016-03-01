<?php
/**
 * Copyright 2016 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 */

/**
 * Kronolith_Attendee represents an attendee.
 *
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 *
 * @property-read Horde_Mail_Rfc822_Address $adressObject An address object
 *                representation of this attendee.
 */
class Kronolith_Attendee implements Serializable
{
    /**
     * The attendee's email address.
     *
     * @var string
     */
    public $email;

    /**
     * The attendee's full name.
     *
     * @var string
     */
    public $name;

    /**
     * The attendee's role.
     *
     * One of the Kronolith::PART_* constants
     *
     * @var integer
     */
    public $role;

    /**
     * The attendee's response.
     *
     * One of the Kronolith::RESPONSE_* constants
     *
     * @var integer
     */
    public $response;

    /**
     * Constructor.
     *
     * @param array $params  Attendee properties:
     *  - 'email':    (string) The email address of the attendee.
     *  - 'role':     (integer) The role code of the attendee. One of the
     *                Kronolith::PART_* constants. Default:
     *                Kronolith::PART_REQUIRED
     *  - 'response': (integer) The response code of the attendee. One of the
     *                Kronolith::RESPONSE_* constants. Default:
     *                Kronolith::RESPONSE_NONE
     *  - 'name':     (string) The name of the attendee.
     */
    public function __construct($params)
    {
        if (!isset($params['email'])) {
            throw new InvalidArgumentException('\'email\' parameter is missing');
        }

        $params = array_merge(
            array(
                'role'     => Kronolith::PART_REQUIRED,
                'response' => Kronolith::RESPONSE_NONE,
                'name'     => null
            ),
            $params
        );
        $this->email    = $params['email'];
        $this->name     = $params['name'];
        $this->role     = $params['role'];
        $this->response = $params['response'];
    }

    /**
     * Migrates data from an old attendee structure.
     *
     * @param string $email  The attendee's email address.
     * @param array $data    The attendee data from before Kronolith 5.
     */
    public static function migrate($email, $data)
    {
        return new self(array(
            'email'    => $email,
            'name'     => isset($data['name']) ? $data['name'] : null,
            'role'     => $data['attendance'],
            'response' => $data['response']
        ));
    }

    /**
     */
    public function __get($what)
    {
        switch ($what) {
        case 'addressObject':
            $address = new Horde_Mail_Rfc822_Address($this->email);
            if (!empty($this->name)) {
                $address->personal = $this->name;
            }
            return $address;

        case 'displayName':
            if (strlen($this->name)) {
                return $this->name;
            }
            return $this->email;
        }
    }

    /**
     * Returns whether an email address matches this attendee.
     *
     * @param string $email           An email address.
     * @param boolean $caseSensitive  Whether to match case-sensitive.
     *
     * @return boolean  True if the email address matches this attendee.
     */
    public function match($email, $caseSensitive)
    {
        $email = new Horde_Mail_Rfc822_Address($email);
        return ($caseSensitive && $email->match($this->email)) ||
            (!$caseSensitive && $email->matchInsensitive($this->email));
    }

    /**
     */
    public function __toString()
    {
        return strval($this->addressObject);
    }

    /**
     * Returns a simple object suitable for JSON transport representing this
     * event.
     *
     * @return object  An object respresenting this attendee.
     */
    public function toJson()
    {
        return (object)array(
            'a' => intval($this->role),
            'e' => $this->addressObject->bare_address,
            'r' => intval($this->response),
            'l' => strval($this->addressObject),
        );
    }

    /* Serializable methods. */

    /**
     */
    public function serialize()
    {
        return serialize(array(
            'e' => $this->email,
            'p' => $this->role,
            'r' => $this->response,
            'n' => $this->name,
        ));
    }

    /**
     */
    public function unserialize($data)
    {
        $data = @unserialize($data);
        $this->email    = $data['e'];
        $this->role     = $data['p'];
        $this->response = $data['r'];
        $this->name     = $data['n'];
    }
}
