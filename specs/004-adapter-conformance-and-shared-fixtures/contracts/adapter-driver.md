# Contract: adapter driver (test helper)

Lives in `tests/helpers/form-drivers/`. One driver per production form adapter. Drivers are test code and never ship (`tests/` is in `.distignore`, and US4 checks the zip).

## Interface

Global namespace and `require_once` loading, like the existing tests (there is no `autoload-dev` in `composer.json`).

```php
interface ClickTrail_Form_Adapter_Driver {
	/** Fully qualified production adapter class this driver exercises. */
	public static function adapter_class(): string;

	/**
	 * Run one submission through the adapter's real hook entry point(s), in host-plugin order.
	 *
	 * @param array<string,string> $cookies   Replaces $_COOKIE for this submission.
	 * @param array<string,string> $posted_ct Submitted ct_* values, keyed by field name.
	 * @param array{form_id?:int, tracking_enabled?:bool} $options
	 */
	public function submit( array $cookies, array $posted_ct, array $options = array() ): void;

	/** Attribution the adapter persisted (entry meta, submission meta, extra data). [] when none. */
	public function persisted(): array;

	/** Rows log_submission() attempted to insert. */
	public function event_rows(): array;

	/** Clear stub storage and the globals this driver owns. */
	public function reset(): void;
}
```

## Rules

1. **Real entry point.** `submit()` calls the adapter method registered for the host hook (`on_submission`, and for Ninja Forms `inject_attribution` first), with arguments built in the shape the host plugin passes (research R3). It does not call a private helper directly.
2. **Real persistence path.** `persisted()` reads what the adapter wrote through stubbed host APIs (`gform_add_meta`, `wpFluent()->table()->insert()`, Ninja `extra`). For CF7, Elementor and WPForms Lite (no entries; `$entry_id` is 0), which persist only through `log_submission()`, `persisted()` returns the `attribution` of the last event row.
2a. **Host filtering is modeled.** Where the host drops submitted keys before the hook (Fluent Forms' whitelist intersection, research R3), the driver applies the same filtering, including any `fluentform/white_listed_fields` callback the adapter registered. A driver that hands `ct_*` keys straight to the hook would test a path that never happens live.
3. **`log_submission()` is observed, not replaced.** The driver stubs `$wpdb` (insert captured into `event_rows()`) rather than overriding `log_submission()`, so the consent check inside it is exercised.
4. **Stub load failure fails.** If a stub class or function cannot be defined (name already taken with an incompatible signature), the driver throws. The conformance test reports that as a failure for that adapter, never `markTestSkipped()` or `markTestIncomplete()` (FR-012).
5. **No inline expectations.** Drivers return what happened; scenarios in `FormAdapterConformanceTest` decide what should have happened, using fixture values where a rule is involved (FR-007).
6. **Isolation.** `reset()` restores `$_COOKIE`, `$_POST` and the driver's stub storage. The conformance test calls it in `setUp()` instead of running in separate processes (research R2).

## Registry

```php
// tests/helpers/form-drivers/registry.php
return array(
	\CLICUTCL\Integrations\Forms\CF7_Adapter::class             => ClickTrail_CF7_Driver::class,
	\CLICUTCL\Integrations\Forms\Elementor_Forms_Adapter::class => ClickTrail_Elementor_Forms_Driver::class,
	\CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter::class    => ClickTrail_Fluent_Forms_Driver::class,
	\CLICUTCL\Integrations\Forms\Gravity_Forms_Adapter::class   => ClickTrail_Gravity_Forms_Driver::class,
	\CLICUTCL\Integrations\Forms\Ninja_Forms_Adapter::class     => ClickTrail_Ninja_Forms_Driver::class,
	\CLICUTCL\Integrations\Forms\WPForms_Adapter::class         => ClickTrail_WPForms_Driver::class,
);
```

`FormAdapterRegistryTest` requires this map's keys to equal `Form_Integration_Manager::ADAPTER_CLASSES` and the set of `Form_Adapter_Interface` implementers under `includes/integrations/forms/`, and each value's `adapter_class()` to equal its key.
