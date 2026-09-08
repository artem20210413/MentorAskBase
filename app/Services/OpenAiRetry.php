<?php

namespace App\Services;

use OpenAI\Exceptions\RateLimitException;

class OpenAiRetry
{
    /**
     * Виконує виклик OpenAI API, автоматично повторюючи його з експоненційною
     * затримкою при перевищенні rate limit (429), замість того щоб одразу
     * провалювати всю обробку документа через тимчасове перевантаження.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function attempt(callable $callback, int $maxAttempts = 5, int $baseDelayMs = 1000): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $callback();
            } catch (RateLimitException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                usleep($baseDelayMs * 2 ** ($attempt - 1) * 1000);
                $attempt++;
            }
        }
    }
}
