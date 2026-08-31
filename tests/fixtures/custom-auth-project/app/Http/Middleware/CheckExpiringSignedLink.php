<?php

namespace App\Http\Middleware;

/**
 * Two hops from the framework class: the walk has to keep following parents,
 * not stop at the first one it cannot match.
 */
class CheckExpiringSignedLink extends CheckSignedLink {}
