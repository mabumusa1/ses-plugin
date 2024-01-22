<?php
/*
* @copyright   2022 Steer Campaign. All rights reserved
* @author      Steer Campaign <m.abumusa@steercampaign.com>
*
* @link        https://steercampaign.com
*
*/

declare(strict_types=1);

namespace MauticPlugin\ScMailerSesBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\ScMailerSesBundle\Entity\SesSetting;
use MauticPlugin\ScMailerSesBundle\Helper\SesHelper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;

class ScMailerSesController extends CommonController
{
    /**
     * @return JsonResponse|RedirectResponse|Response
     */
    public function indexAction()
    {
        if (!$this->user->isAdmin()) {
            return $this->accessDenied();
        }

        $em           = $this->getDoctrine()->getManager();
        $repository   = $em->getRepository(SesSetting::class);
        $settings     = $repository->findAll();

        return $this->delegateView(
            [
                'viewParameters' => [
                    'items'       => $settings,
                ],
                'contentTemplate' => '@ScMailerSes/Show/index.html.twig',
                'passthroughVars' => [
                ],
            ]
        );
    }

    /**
     * @return JsonResponse|RedirectResponse|Response
     */
    public function deleteAction(Request $request)
    {
        if (!$this->user->isAdmin()) {
            return $this->accessDenied();
        }

        $returnUrl = $this->generateUrl('plugin_scmailerses_admin');
        $success   = 0;
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'contentTemplate' => 'MauticPlugin\ScMailerSesBundle\Controller\ScMailerSesController::indexAction',
            'passthroughVars' => [
                'success'       => $success,
            ],
        ];

        if (!$request->get('objectId')) {
            return $this->accessDenied();
        }

        $objectId   = $request->get('objectId');
        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository(SesSetting::class);
        $setting    = $repository->find($objectId);

        if (null === $setting) {
            $flashes[] = [
                'type'    => 'error',
                'msg'     => 'mautic.plugin.scmailerses.form.accessKey.error.notfound',
                'msgVars' => ['%id%' => $objectId],
            ];
        } else {
            $name   = $setting->getAccessKey();
            $dsn    = Dsn::fromString($this->coreParametersHelper->getParameter('mailer_dsn'));
            $region = $dsn->getOption('region');
            if (null === $region) {
                $flashes[] = [
                    'type'    => 'error',
                    'msg'     => 'mautic.plugin.scmailerses.form.region.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } else {
                $helper = new SesHelper($this->get('monolog.logger.mautic'), $dsn->getUser(), $dsn->getPassword(), $region);
                $failed = $helper->deleteTemplates($setting->getTemplates());
                if ($failed) {
                    $setting->setTemplates($failed);
                    $em->persist($setting);
                    $em->flush();
                    $flashes[] = [
                        'type'    => 'notice',
                        'msg'     => 'mautic.plugin.scmailerses.failed.delete.templates',
                        'msgVars' => [
                            '%name%' => $name,
                            '%id%'   => $objectId,
                        ],
                    ];
                } else {
                    $em->remove($setting);
                    $em->flush();
                    $flashes[] = [
                        'type'    => 'notice',
                        'msg'     => 'mautic.core.notice.deleted',
                        'msgVars' => [
                            '%name%' => $name,
                            '%id%'   => $objectId,
                        ],
                    ];
                }
            }
        }

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                [
                    'flashes' => $flashes,
                ]
            )
        );
    }
}
