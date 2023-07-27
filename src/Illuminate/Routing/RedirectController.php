<?php

namespace Illuminate\Routing;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedirectController extends Controller
{
    public function action($destination, $status)
    {
        return new RedirectResponse($destination, $status);
    }
}
