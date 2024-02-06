<?php
/**
 * @author    Craig Gosman <craig@ingenerator.com>
 * @licence   proprietary
 */

namespace Ingenerator\ScheduledTaskRunner;

interface CronjobRunnerFactory
{
    public function getController(): CronController;
}
