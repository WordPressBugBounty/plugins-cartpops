<?php
/**
 * Internal value-free serialized reader exception.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Carries only a value-free parser reason code.
 */
final class SafeSerializedReaderException extends \RuntimeException {
}
