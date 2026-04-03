<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTenantCompany;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use ResolvesTenantCompany;
}
