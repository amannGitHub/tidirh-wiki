<?php
/**
 * Options for the groupmail plugin
 *
 * @license GNU General Public License 3 <http://www.gnu.org/licenses/>
 * @author Bob Baddeley <bob@bobbaddeley.com>
 * @author Marvin Thomas Rabe <mrabe@marvinrabe.de>
 * @author Roland Wunderling <bzfwunde@gmail.com>
 */

$meta['default'] = array('string');
$meta['sendlog'] = array('string');
$meta['captcha'] = array('onoff');
$meta['recaptchakey'] = array('string');
$meta['recaptchasecret'] = array('string');
$meta['recaptchalayout']  = array('multichoice','_choices' => array('red','white','blackglass','clean'));
