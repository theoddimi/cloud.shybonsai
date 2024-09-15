<?php

namespace Codexdelta\Libs\Exceptions;

use Exception;

class FourOhFourException extends Exception
{
    public function __construct($error, $response = null, $errorBag = 'default')
    {
        parent::__construct($error, $response);
    }

    protected function shouldReport()
    {

    }

    protected function shouldNotReport()
    {

    }

    public function render()
    {

    }
}