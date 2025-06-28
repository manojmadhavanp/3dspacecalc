```php
<?php
namespace App\Exception;

/**
 * Custom exception for Authentication/Authorization specific errors.
 * Allows setting a specific HTTP status code.
 */
class AuthException extends \RuntimeException {
    // You can add custom properties if needed, for example, an error code specific to your app
    // protected $appErrorCode;

    public function __construct($message = "", $code = 0, \Throwable $previous = null /*, $appErrorCode = null*/) {
        // If $code is 0, it might default to 500 or whatever RuntimeException does.
        // It's often better to ensure a specific HTTP error code (401, 403) is passed.
        // Defaulting common auth failure messages to 401 if code not provided.
        if ($code === 0) {
            if (str_contains(strtolower($message), 'token') || str_contains(strtolower($message), 'authentication required')) {
                 $code = 401; // Unauthorized
            } elseif (str_contains(strtolower($message), 'permission') || str_contains(strtolower($message), 'access denied')) {
                 $code = 403; // Forbidden
            }
            // else leave as 0 to become 500 or as passed
        }
        parent::__construct($message, $code, $previous);
        // $this->appErrorCode = $appErrorCode;
    }

    // public function getAppErrorCode() {
    //     return $this->appErrorCode;
    // }
}
?>
```
