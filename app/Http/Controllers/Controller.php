<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 11+ ships a bare base controller — AuthorizesRequests is no longer
 * included by default, so authorize() and authorizeResource() are undefined
 * until the trait is added back here.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
