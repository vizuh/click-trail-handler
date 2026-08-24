<?php
/**
 * Versioned consent decision value object.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Decision_V1
 */
final class Decision_V1 {
	public const SCHEMA_VERSION = 1;

	public const DECISION_GRANTED    = 'granted';
	public const DECISION_DENIED     = 'denied';
	public const DECISION_UNRESOLVED = 'unresolved';
	public const DECISION_INVALID    = 'invalid';

	public const BASIS_ADMIN_OVERRIDE     = 'administrative_override';
	public const BASIS_CMP                = 'cmp';
	public const BASIS_PLUGIN_BANNER      = 'plugin_banner';
	public const BASIS_BRIDGE_MIRROR      = 'bridge_mirror';
	public const BASIS_FALLBACK_TIMEOUT   = 'fallback_timeout';
	public const BASIS_NOT_REQUIRED       = 'not_required';
	public const BASIS_LEGACY_UNVERSIONED = 'legacy_unversioned';
	public const BASIS_NONE               = 'none';

	/**
	 * Create a versioned consent decision.
	 *
	 * @param string   $decision   Decision state.
	 * @param string   $basis      Policy basis.
	 * @param int      $revision   Monotonic revision.
	 * @param int|null $captured_at Capture timestamp.
	 * @param int|null $expires_at Expiry timestamp.
	 * @param string   $source_id  Bounded source identifier.
	 * @throws \InvalidArgumentException When decision, basis, or revision is invalid.
	 */
	public function __construct(
		private string $decision,
		private string $basis,
		private int $revision = 0,
		private ?int $captured_at = null,
		private ?int $expires_at = null,
		private string $source_id = ''
	) {
		if ( ! in_array( $decision, self::decisions(), true ) ) {
			throw new \InvalidArgumentException( 'Invalid consent decision.' );
		}
		if ( ! in_array( $basis, self::bases(), true ) ) {
			throw new \InvalidArgumentException( 'Invalid consent basis.' );
		}
		if ( $revision < 0 ) {
			throw new \InvalidArgumentException( 'Consent revision cannot be negative.' );
		}
		$this->source_id = substr( preg_replace( '/[^a-z0-9_.-]/', '', strtolower( $source_id ) ) ?? '', 0, 64 );
	}

	/**
	 * Return the decision state.
	 *
	 * @return string
	 */
	public function decision(): string {
		return $this->decision;
	}

	/**
	 * Return the policy basis.
	 *
	 * @return string
	 */
	public function basis(): string {
		return $this->basis;
	}

	/**
	 * Return the monotonic revision.
	 *
	 * @return int
	 */
	public function revision(): int {
		return $this->revision;
	}

	/**
	 * Check whether this decision permits processing.
	 *
	 * @return bool
	 */
	public function allows_processing(): bool {
		return self::BASIS_NOT_REQUIRED === $this->basis || self::DECISION_GRANTED === $this->decision;
	}

	/**
	 * Return the serializable snapshot.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'decision'       => $this->decision,
			'basis'          => $this->basis,
			'revision'       => $this->revision,
			'captured_at'    => $this->captured_at,
			'expires_at'     => $this->expires_at,
			'source_id'      => $this->source_id,
		);
	}

	/**
	 * Return the JSON snapshot.
	 *
	 * @return string
	 * @throws \JsonException When encoding fails.
	 */
	public function to_json(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure contract has no WordPress runtime dependency.
		return json_encode( $this->to_array(), JSON_THROW_ON_ERROR );
	}

	/**
	 * Rehydrate a validated decision snapshot.
	 *
	 * @param array $snapshot Serialized decision snapshot.
	 * @return self
	 * @throws \InvalidArgumentException When the snapshot schema is invalid.
	 */
	public static function from_array( array $snapshot ): self {
		if ( self::SCHEMA_VERSION !== ( $snapshot['schema_version'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Invalid consent schema version.' );
		}

		return new self(
			(string) ( $snapshot['decision'] ?? '' ),
			(string) ( $snapshot['basis'] ?? '' ),
			(int) ( $snapshot['revision'] ?? 0 ),
			isset( $snapshot['captured_at'] ) ? (int) $snapshot['captured_at'] : null,
			isset( $snapshot['expires_at'] ) ? (int) $snapshot['expires_at'] : null,
			(string) ( $snapshot['source_id'] ?? '' )
		);
	}

	/**
	 * Return allowed decision values.
	 *
	 * @return array
	 */
	private static function decisions(): array {
		return array(
			self::DECISION_GRANTED,
			self::DECISION_DENIED,
			self::DECISION_UNRESOLVED,
			self::DECISION_INVALID,
		);
	}

	/**
	 * Return allowed policy bases.
	 *
	 * @return array
	 */
	private static function bases(): array {
		return array(
			self::BASIS_ADMIN_OVERRIDE,
			self::BASIS_CMP,
			self::BASIS_PLUGIN_BANNER,
			self::BASIS_BRIDGE_MIRROR,
			self::BASIS_FALLBACK_TIMEOUT,
			self::BASIS_NOT_REQUIRED,
			self::BASIS_LEGACY_UNVERSIONED,
			self::BASIS_NONE,
		);
	}
}
