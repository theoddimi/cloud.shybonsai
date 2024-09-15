<?php

use Codexdelta\App\Controllers\HomeController;
use Codexdelta\Libs\Router\Router;

return function()
{
    Router::get('/home', [HomeController::class, 'index']);
};
