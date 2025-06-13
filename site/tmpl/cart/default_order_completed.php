<div>

    Ευχαριστουμε για την αγορα σου<br>

    <?php

    // TODO: Error handling for missing template.
    use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;

    echo PluginLayoutHelper::pluginLayout(
        $this->event->onOrderCompleteView->getLayoutPluginType(),
        $this->event->onOrderCompleteView->getLayoutPluginName(),
        $this->event->onOrderCompleteView->getLayout()
    )->render($this->event->onOrderCompleteView->getLayoutData());

//    echo "<pre>";
//    print_r($this->event);
//    echo "</pre>";

    ?>

    <a href="<?php echo \Joomla\CMS\Uri\Uri::root(); ?>">Πίσω στην αρχική</a>

</div>