<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2020 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die();

/**
 * View Template for the guest users login form
 *
 * Called from default.php
 */

use Joomla\CMS\Language\Text;

/** @var \Akeeba\Engage\Site\View\Comments\Html $this */

$loginModule     = $this->container->params->get('login_module', '');
$moduleContent   = empty($loginModule) ? '' : trim($this->container->template->loadModule($loginModule));
$positionContent = trim($this->container->template->loadPosition('engage-login'));

/**
 * A reason for this to happen is that site owner wants discussion to be open to invitation-only members of the site but
 * visible by anyone. This is mostly relevant in political organizations, NGOs and local / closed community
 * organizations where a small number of people are openly discussing a public interest issue but they don't want to
 * allow random people to detract the conversation.
 */
if (empty($moduleContent) && empty($positionContent))
{
	return;
}
?>
<footer id="akeeba-engage-login">
	<h3>
		<?= Text::_('COM_ENGAGE_COMMENTS_LOGIN_HEAD') ?>
	</h3>

	<?= $moduleContent ?>
	<?= $positionContent ?>
</footer>