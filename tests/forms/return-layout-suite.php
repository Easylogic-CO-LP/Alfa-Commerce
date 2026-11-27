<?php

/**
 * A RECORD OPENED FROM ANOTHER PAGE GOES BACK THERE ON CLOSE AND SAVE & CLOSE.
 *
 * What this pins, without a database:
 *
 *   R1   the shared layout form/return.php prints one hidden field, return, with the request's return value and
 *        nothing else: empty when the form was opened from its list, the value as given otherwise, base64 characters
 *        only;
 *   R2   AlfaHelper::returnPage() is the base64 of the request address as Uri gives it, and on a record form the
 *        address of the form's edit task (the one way a form is reached), which Uri::isInternal() takes;
 *   R3   AlfaHelper::withReturn() appends the page, url encoded, to a link that opens a record and leaves a list link
 *        as it is; the hidden field reads back exactly what was sent;
 *   R4   every record form renders the layout beside its form token, and each passes php -l.
 *
 * Run from the docroot:
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/forms/return-layout-suite.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

define('_JEXEC', 1);
\defined('JDEBUG') or \define('JDEBUG', false);
// The administrator directory from __DIR__, booted through administrator/includes like the real backend.
define('JPATH_BASE', \dirname(__DIR__, 4));

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/administrator/index.php?option=com_alfa&view=priceaudit';
$_SERVER['SCRIPT_NAME'] = '/administrator/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Joomla\Session\SessionInterface;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Session::class, 'session.cli')
    ->alias(SessionInterface::class, 'session.cli');

$app = $container->get(AdministratorApplication::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();

// The admin template pinned by hand, as a page render would set it: the layout renders without a database.
(new ReflectionProperty(CMSApplication::class, 'template'))
    ->setValue($app, (object) ['template' => 'atum', 'params' => new Registry(), 'parent' => '', 'inheritable' => 0]);
$app->set('themeInherits', '');

$component = JPATH_ADMINISTRATOR . '/components/com_alfa';
$pass = 0;
$fail = 0;

/**
 * @param string $what What is being asserted, in words.
 * @param mixed  $got  What the code did.
 * @param mixed  $want What it had to do.
 */
$check = static function (string $what, $got, $want) use (&$pass, &$fail): void {
    if ($got === $want) {
        $pass++;
        echo "  ok    {$what}\n";

        return;
    }

    $fail++;
    echo "  FAIL  {$what}\n        got  " . json_encode($got) . "\n        want " . json_encode($want) . "\n";
};

$layout = new FileLayout('form.return', $component . '/layouts');
$input = $app->getInput();
$input->set('option', 'com_alfa');
$input->set('view', 'priceaudit');
$token = base64_encode('index.php?option=com_alfa&view=priceaudit');

// ── R1: the layout ───────────────────────────────────────────────────────────
echo "R1 the return layout\n";

$check('R1a no return value: an empty hidden field, nothing else', trim($layout->render([])), '<input type="hidden" name="return" value="">');
$input->set('return', $token);
$check('R1b a return value: the same value in the hidden field', trim($layout->render([])), '<input type="hidden" name="return" value="' . $token . '">');
$input->set('return', $token . '<x>"');
$check('R1c only base64 characters pass to the field', trim($layout->render([])), '<input type="hidden" name="return" value="' . $token . 'x">');
$input->set('return', null);

// ── R2: the page's address ───────────────────────────────────────────────────
echo "R2 the way back\n";

$check('R2a returnPage() is the base64 of the request address, as Uri gives it', base64_decode(AlfaHelper::returnPage(), true), Uri::getInstance()->toString());
$check('R2b Uri::isInternal() takes it', Uri::isInternal(base64_decode(AlfaHelper::returnPage(), true)), true);

$input->set('view', 'discount');
$input->set('layout', 'edit');
$input->set('id', 7);
$check('R2c on a record form the way back is the form\'s edit task', base64_decode(AlfaHelper::returnPage(), true), 'index.php?option=com_alfa&task=discount.edit&id=7');
$check('R2d Uri::isInternal() takes that address too', Uri::isInternal(base64_decode(AlfaHelper::returnPage(), true)), true);
$input->set('view', 'priceaudit');
$input->set('layout', null);
$input->set('id', null);

// ── R3: the links ────────────────────────────────────────────────────────────
echo "R3 the links\n";

$link = AlfaHelper::withReturn('index.php?option=com_alfa&task=item.edit&id=5');
$check('R3a a link that opens a record carries the page, url encoded', $link, 'index.php?option=com_alfa&task=item.edit&id=5&return=' . rawurlencode(AlfaHelper::returnPage()));
$check('R3b a given return is carried as given', AlfaHelper::withReturn('index.php?option=com_alfa&task=order.edit&id=9', 'QUJD'), 'index.php?option=com_alfa&task=order.edit&id=9&return=QUJD');
$check('R3c a list link is left as it is', AlfaHelper::withReturn('index.php?option=com_alfa&view=items&filter[stock]=out'), 'index.php?option=com_alfa&view=items&filter[stock]=out');
$check('R3d the field reads back what the link sent', base64_decode(rawurldecode(substr($link, strpos($link, 'return=') + 7)), true), Uri::getInstance()->toString());

// ── R4: every record form ────────────────────────────────────────────────────
echo "R4 the record forms\n";

$forms = glob($component . '/tmpl/*/edit.php') ?: [];
$without = [];
$broken = [];

foreach ($forms as $form) {
    $source = (string) file_get_contents($form);

    if (!str_contains($source, "LayoutHelper::render('form.return'")) {
        $without[] = basename(\dirname($form));
    }

    exec('php -l ' . escapeshellarg($form) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        $broken[] = basename(\dirname($form));
    }
}

$check('R4a the record forms were found', \count($forms) >= 21, true);
$check('R4b every one renders the return layout', $without, []);
$check('R4c every one passes php -l', $broken, []);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
