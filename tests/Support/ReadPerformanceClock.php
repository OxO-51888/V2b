<?php

namespace Tests\Support {
    class ReadPerformanceClock
    {
        public static $now;
    }
}

namespace App\Http\Controllers\V1\Admin {
    function time() { return \Tests\Support\ReadPerformanceClock::$now ?? \time(); }
    function date($format, $timestamp = null) { return \date($format, $timestamp ?? time()); }
}

namespace App\Services {
    function time() { return \Tests\Support\ReadPerformanceClock::$now ?? \time(); }
}
