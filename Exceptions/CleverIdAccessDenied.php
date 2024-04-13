<?php

namespace LGL\Clever\Exceptions;

use Exception;

class CleverIdAccessDenied extends Exception
{
    public function __construct($message = 'Clever has removed access for this resource. Clever ID has been removed and the entity has been soft deleted.', $code = 1001, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
