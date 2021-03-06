<?php
/**
 * Statusengine Worker
 * Copyright (C) 2016-2017  Daniel Ziegler
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Statusengine;

use Statusengine\Config\Downtime;
use Statusengine\Config\WorkerConfig;
use Statusengine\ValueObjects\Acknowledgement;
use Statusengine\ValueObjects\Notification;
use Statusengine\ValueObjects\Pid;
use Statusengine\Redis\Statistics;


class MiscChild extends Child {

    /**
     * @var GearmanWorker
     */
    private $MiscGearmanWorker;

    /**
     * @var WorkerConfig
     */
    private $NotificationConfig;

    /**
     * @var WorkerConfig
     */
    private $AcknowledgementConfig;

    /**
     * @var WorkerConfig
     */
    private $DowntimeConfig;

    /**
     * @var Config
     */
    private $Config;

    /**
     * @var ChildSignalHandler
     */
    private $SignalHandler;

    /**
     * @var Statistics
     */
    private $Statistics;

    /**
     * Storage Backend
     */
    private $StorageBackend;

    /**
     * MiscChild constructor.
     * @param ChildSignalHandler $SignalHandler
     * @param Config $Config
     * @param \Statusengine\Config\Notification $NotificationConfig
     * @param \Statusengine\Config\Acknowledgement $AcknowledgementConfig
     * @param Downtime $DowntimeConfig
     * @param Pid $Pid
     * @param Statistics $Statistics
     * @param $StorageBackend
     */
    public function __construct(
        ChildSignalHandler $SignalHandler,
        Config $Config,
        $NotificationConfig,
        $AcknowledgementConfig,
        $DowntimeConfig,
        Pid $Pid,
        Statistics $Statistics,
        $StorageBackend
    ) {
        $this->SignalHandler = $SignalHandler;
        $this->Config = $Config;

        $this->NotificationConfig = $NotificationConfig;
        $this->AcknowledgementConfig = $AcknowledgementConfig;
        $this->DowntimeConfig = $DowntimeConfig;

        $this->parentPid = $Pid->getPid();
        $this->Statistics = $Statistics;
        $this->StorageBackend = $StorageBackend;

        $this->SignalHandler->bind();

        $this->MiscGearmanWorker = new GearmanWorker($this->NotificationConfig, $Config);
        $this->MiscGearmanWorker->addQueue($this->AcknowledgementConfig);
        $this->MiscGearmanWorker->addQueue($this->DowntimeConfig);
        $this->MiscGearmanWorker->connect();
    }


    public function loop() {
        $this->Statistics->setPid($this->Pid);
        $StatisticType = new Config\StatisticType();
        $StatisticType->isMiscStatistic();
        $this->Statistics->setStatisticType($StatisticType);

        //Connect to backend
        $this->StorageBackend->connect();

        while (true) {
            $jobData = $this->MiscGearmanWorker->getJob();
            if ($jobData !== null) {
                if (property_exists($jobData, 'contactnotificationmethod')) {
                    $this->handleNotifications($jobData);
                }
                if (property_exists($jobData, 'acknowledgement')) {
                    $this->handleAcknowledgements($jobData);
                }
                if (property_exists($jobData, 'downtime')) {
                    $this->handleDowntime($jobData);
                }
            }

            $this->StorageBackend->dispatch();

            $this->Statistics->dispatch();

            $this->SignalHandler->dispatch();
            $this->checkIfParentIsAlive();
        }
    }

    /**
     * @param \stdClass $jobData
     */
    private function handleNotifications($jobData) {
        $Notification = new Notification($jobData);
        if ($Notification->isValidNotification()) {
            $this->StorageBackend->saveNotification(
                $Notification
            );
            $this->Statistics->increase();
        }

    }

    /**
     * @param \stdClass $jobData
     */
    private function handleAcknowledgements($jobData) {
        $Acknowledgement = new Acknowledgement($jobData);
        $this->StorageBackend->saveAcknowledgement(
            $Acknowledgement
        );
        $this->Statistics->increase();
    }

    /**
     * @param \stdClass $jobData
     */
    private function handleDowntime($jobData) {

        $Downtime = new \Statusengine\ValueObjects\Downtime($jobData);

        if ($Downtime->isHostDowntime()) {
            $DowntimehistoryBackend = $this->StorageBackend->getHostDowntimehistoryBackend();
            $ScheduleddowntimeBackend = $this->StorageBackend->getHostScheduleddowntimeBackend();
        } else {
            $DowntimehistoryBackend = $this->StorageBackend->getServiceDowntimehistoryBackend();
            $ScheduleddowntimeBackend = $this->StorageBackend->getServiceScheduleddowntimeBackend();
        }

        if (!$Downtime->wasDowntimeDeleted() && !$Downtime->wasDowntimeDeleted()) {
            //Filter delete event
            $DowntimehistoryBackend->saveDowntime($Downtime);
        }

        if ($Downtime->wasDowntimeStopped() || $Downtime->wasDowntimeDeleted()) {
            //User delete the downtime or it is expired
            $ScheduleddowntimeBackend->deleteDowntime($Downtime);
        } else {
            if (!$Downtime->wasDowntimeDeleted() && !$Downtime->wasDowntimeDeleted()) {
                //Filter delete event
                $ScheduleddowntimeBackend->saveDowntime($Downtime);
            }
        }
    }
}
