<?php

namespace App\Http\Middleware;

/**
 * Deliberately broken: CyclicA and CyclicB extend each other. This can only
 * happen with a class PHP could never load, but Brain parses source without
 * executing it, so the extends walk in SecurityAnalyzer::isAuthClass() must
 * terminate on the cycle instead of recursing until memory is exhausted.
 */
class CyclicA extends CyclicB {}
