<div>

    <?php

    use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;

    if (!empty($this->event->onOrderProcessView)):?>
            <div class="item-shipment-method">
                <?php
                echo PluginLayoutHelper::pluginLayout(
                    $this->event->onOrderProcessView->getLayoutPluginType(),
                    $this->event->onOrderProcessView->getLayoutPluginName(),
                    $this->event->onOrderProcessView->getLayout(),
                )->render($this->event->onOrderProcessView->getLayoutData());
                ?>
            </div>
    <?php endif ?>

    <button onclick="location.reload();">Retry paying</button>
</div>