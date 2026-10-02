<?php
/**
 * Ambiguous durable cart commit result.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Indicates that the server cannot safely tell a shopper to retry a mutation. */
final class CartCommitOutcomeUnknownException extends \RuntimeException {}
