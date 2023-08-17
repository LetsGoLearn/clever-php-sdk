<?php

namespace LGL\Clever\Exceptions;


class EmailInUse extends \Exception
{
    public function __construct($message = 'eMail already in use for this client. Can\t create a New User', $code = 1003, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
