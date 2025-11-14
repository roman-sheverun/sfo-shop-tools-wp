<?php

namespace Rvx\Rest\Middleware;

use WP_REST_Request;
use Rvx\Utilities\Auth\WpUser;
class AuthMiddleware
{
    /**
     * Determine if the current request is authorized.
     *
     * Authorization is granted if:
     * User is logged in AND has sufficient capabilities.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function handle(WP_REST_Request $request) : bool
    {
        if (!WpUser::isLoggedIn()) {
            return \false;
        }
        return WpUser::can();
    }
}
