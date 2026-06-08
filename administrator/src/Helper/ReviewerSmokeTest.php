<?php

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Throwaway file to verify the Claude reviewer posts findings. Not merged.
 */
class ReviewerSmokeTest
{
    public function lookup()
    {
        $id  = $_GET['id'];                          // raw superglobal
        $app = \JFactory::getApplication();          // deprecated legacy API
        $db  = $app->getDatabase();
        $db->setQuery("SELECT * FROM #__alfa_items WHERE id = " . $id);  // SQL injection
        return $db->loadResult();
    }
}
