<?php

declare(strict_types=1);

namespace Cybay\CbPlotdesk\Reaction;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

/**
 * Exportiert seo_title und description eines Seitenbaums für Plotdesk.
 *
 * Erwarteter Payload:
 * {
 *   "rootPageId": 1,
 *   "languageId": 0
 * }
 */
class PageSeoExportReaction implements ReactionInterface
{
    private const FIELDS = 'uid,pid,doktype,title,seo_title,description';

    /** Nur Standard-Seiten (doktype 1) in die Ergebnisliste aufnehmen */
    private const DOKTYPE_STANDARD = 1;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public static function getType(): string
    {
        return 'cb-plotdesk-page-seo-export';
    }

    public static function getDescription(): string
    {
        return 'Plotdesk: Seitenbaum seo_title und description exportieren';
    }

    public static function getIconIdentifier(): string
    {
        return 'content-page';
    }

    public function react(
        ServerRequestInterface $request,
        array $payload,
        ReactionInstruction $reaction
    ): ResponseInterface {
        $rootPageId = (int)($payload['rootPageId'] ?? 0);
        $languageId = (int)($payload['languageId'] ?? 0);

        if ($rootPageId <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'rootPageId fehlt oder ist ungültig',
            ], 400);
        }

        $context = new Context([
            'language' => new LanguageAspect(
                $languageId,
                $languageId,
                LanguageAspect::OVERLAYS_ON
            ),
        ]);
        $pageRepository = new PageRepository($context);

        $rootPage = $pageRepository->getPage($rootPageId);
        if ($rootPage === []) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Root-Seite nicht gefunden',
            ], 404);
        }

        $pages = [];
        $this->collectPages($pageRepository, $rootPage, $pages);

        return $this->jsonResponse([
            'success' => true,
            'rootPageId' => $rootPageId,
            'languageId' => $languageId,
            'pages' => $pages,
        ]);
    }

    /**
     * Sammelt Standard-Seiten (doktype 1) flach in einer Liste.
     * Andere Doktypes werden übersprungen, der Baum darunter aber weiter durchlaufen.
     *
     * @param array<string, mixed> $page
     * @param list<array<string, mixed>> $pages
     */
    private function collectPages(PageRepository $pageRepository, array $page, array &$pages): void
    {
        if ((int)($page['doktype'] ?? 0) === self::DOKTYPE_STANDARD) {
            $pages[] = [
                'uid' => (int)$page['uid'],
                'pid' => (int)$page['pid'],
                'title' => (string)($page['title'] ?? ''),
                'seo_title' => (string)($page['seo_title'] ?? ''),
                'description' => (string)($page['description'] ?? ''),
            ];
        }

        foreach ($pageRepository->getMenu((int)$page['uid'], self::FIELDS) as $child) {
            $this->collectPages($pageRepository, $child, $pages);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $statusCode = 200): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(
                $this->streamFactory->createStream(
                    json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                )
            );
    }
}
