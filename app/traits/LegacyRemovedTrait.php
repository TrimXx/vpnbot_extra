<?php

trait LegacyRemovedTrait
{
    protected function legacyRemovedNotice(string $service): void
    {
        $message = $service . ' removed in v3';
        if (!empty($this->input['callback_id'])) {
            $this->answer($this->input['callback_id'], $message, true);
        }
    }

    protected function legacyRemovedMenu(string $service): void
    {
        $this->legacyRemovedNotice($service);
        $this->menu();
    }

    protected function finishQrMenuRefresh(callable $refresh): void
    {
        if (!empty($this->getPacConf()['blinkmenu']) && !empty($this->input['callback_id'])) {
            $this->answer($this->input['callback_id']);
        }
        $refresh();
    }
}
