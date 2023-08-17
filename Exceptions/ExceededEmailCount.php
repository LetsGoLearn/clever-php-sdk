<?php

namespace LGL\Clever\Exceptions;


class ExceededEmailCount extends \Exception
{
    public function __construct($message = 'Exceeded email count. We found multiple instances of an email address in the system. Contact Engineering to resolve.', $code = 1006, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
