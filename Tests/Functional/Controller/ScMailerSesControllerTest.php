<?php

declare(strict_types=1);

namespace MauticPlugin\ScMailerSesBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;

final class ScMailerSesControllerFunctionalTest extends MauticMysqlTestCase
{
    public function testIndexAction(): void
    {
        $this->client->request('GET', 's/scmailerses/admin');
        $response = $this->client->getResponse();
        $this->assertTrue($response->isOk());
    }

    public function testDeleteAction(): void
    {
        $this->client->request('POST', 's/scmailerses/delete');
        $response = $this->client->getResponse();
        dd($response);
        $this->assertTrue($response->isOk());
    }
}
