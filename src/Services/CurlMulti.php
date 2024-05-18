<?php
namespace phasync\Services;

use CurlHandle;
use CurlMultiHandle;
use phasync;

/**
 * Provides asynchronous running of curl_handles within phasync. To
 * run the curl handle, use `CurlMulti::await($curlHandle)` from inside
 * a coroutine.
 * 
 * @package phasync
 */
final class CurlMulti {

    private static ?CurlMultiHandle $curlMulti = null;
    private static bool $isRunning = false;

    public static function await(CurlHandle $ch) {
        if (self::$curlMulti === null) {
            self::$curlMulti = \curl_multi_init();
        }
        \curl_multi_add_handle(self::$curlMulti, $ch);
        if (!self::$isRunning) {
            phasync::service(static function() {
                try {
                    self::$isRunning = true;
                    do {
                        phasync::sleep(0.02);
                        $status = \curl_multi_exec(self::$curlMulti, $active);
                        /**
                         * @var array
                         */
                        while (false !== ($info = \curl_multi_info_read(self::$curlMulti))) {
                            if ($info['msg'] === \CURLMSG_DONE) {
                                // Activate the fiber that invoked the await() function
                                phasync::raiseFlag($info['handle']);
                            }
                        }
                    } while ($active && $status === \CURLM_OK);    
                } finally {
                    self::$isRunning = false;
                }
            });
        }
        // Block the Fiber that invoked this function
        phasync::awaitFlag($ch);
        $result = \curl_multi_getcontent($ch) ?? false;
        \curl_multi_remove_handle(self::$curlMulti, $ch);
        return $result;
    }
}