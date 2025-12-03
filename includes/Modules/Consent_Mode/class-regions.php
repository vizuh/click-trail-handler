<?php
/**
 * Class ClickTrail\Modules\Consent_Mode\Regions
 *
 * @package   ClickTrail
 */

namespace ClickTrail\Modules\Consent_Mode;

/**
 * Class containing region data.
 */
class Regions {

	/**
	 * Gets the list of regions.
	 *
	 * @return array List of regions.
	 */
	public static function get_regions() {
		return array(
			'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR',
			'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MT', 'NL', 'NO', 'PL', 'PT',
			'RO', 'SE', 'SI', 'SK',
		);
	}
}
