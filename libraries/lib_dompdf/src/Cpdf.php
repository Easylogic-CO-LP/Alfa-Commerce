<?php

/**
 * Joomla's autoloader looks for the class Dompdf\Cpdf in this file, as it does for every class of the Dompdf
 * namespace under src/. dompdf keeps that class in lib/Cpdf.php, beside the fonts and the colour profile it reads
 * from its own folder, so this file loads it from there. It belongs to the Joomla packaging of dompdf, not to dompdf.
 */

\defined('_JEXEC') or die;

require_once \dirname(__DIR__) . '/lib/Cpdf.php';
