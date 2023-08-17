<?php

namespace LGL\Clever\Exceptions;


class EmailMissMatch extends \Exception
{
    public function __construct($message = 'The system users eMail is already in use but the email doesn\'t match the one from Clever. Possibly two different users in the system. Contact Engineering to correct the records.', $code = 1004, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
