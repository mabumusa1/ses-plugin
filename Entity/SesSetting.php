<?php
/*
* @copyright   2022 Steer Campaign. All rights reserved
* @author      Steer Campaign <m.abumusa@steercampaign.com>
*
* @link        https://steercampaign.com
*
*/

declare(strict_types=1);

namespace MauticPlugin\ScMailerSesBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Class SesSetting.
 */
class SesSetting
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var string
     */
    private $accessKey;

    /**
     * @var int
     */
    private $maxSendRate;

    /**
     * @var array<string>
     */
    private $templates;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder
            ->setTable('plugin_ses_settings')
            ->addId()
            ->addIndex(['access_key'], 'access_key')
            ->addNamedField('accessKey', Types::STRING, 'access_key')
            ->addNamedField('maxSendRate', Types::INTEGER, 'max_send_rate')
            ->addNamedField('templates', Types::SIMPLE_ARRAY, 'templates', true);
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getAccessKey(): string
    {
        return $this->accessKey;
    }

    public function setAccessKey(string $accessKey): void
    {
        $this->accessKey = $accessKey;
    }

    public function getMaxSendRate(): int
    {
        return $this->maxSendRate;
    }

    public function setMaxSendRate(int $maxSendRate): void
    {
        $this->maxSendRate = $maxSendRate;
    }

    /**
     * @return array<string>
     */
    public function getTemplates(): array
    {
        return $this->templates;
    }

    /**
     * @param array<string> $templates
     */
    public function setTemplates(array $templates): void
    {
        $this->templates = $templates;
    }
}
