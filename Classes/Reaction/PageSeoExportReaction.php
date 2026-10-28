<?php

declare(strict_types=1);

namespace Cybay\CbPlotdesk\Reaction;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

/**
 * Exportiert SEO-Felder und Content-Kontext eines Seitenbaums für Plotdesk.
 *
 * Sammelt alle Standard-Seiten unterhalb von rootPageId (inkl. Root).
 * Shortcuts (doktype 4) werden aufgelöst und deren Zielseiten mit erfasst.
 * Content (header, subheader, bodytext) wird als strip_tags-Text im Feld "context" geliefert.
 *
 * Erwarteter Payload:
 * {
 *   "rootPageId": 1,
 *   "languageId": 0
 * }
 */
class PageSeoExportReaction implements ReactionInterface
{
    private const PAGE_FIELDS = [
        'uid',
        'pid',
        'doktype',
        'title',
        'seo_title',
        'description',
        'shortcut',
        'shortcut_mode',
        'sys_language_uid',
    ];

    /** Nur Standard-Seiten (doktype 1) in die Ergebnisliste aufnehmen */
    private const DOKTYPE_STANDARD = PageRepository::DOKTYPE_DEFAULT;

    /** Shortcuts werden aufgelöst und die Zielseite exportiert */
    private const DOKTYPE_SHORTCUT = PageRepository::DOKTYPE_SHORTCUT;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ConnectionPool $connectionPool,
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

        $rootPage = $pageRepository->getPage($rootPageId, true);
        if ($rootPage === []) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Root-Seite nicht gefunden',
            ], 404);
        }

        $pagesByUid = [];
        $this->collectPages($pageRepository, $rootPage, $languageId, $pagesByUid);

        return $this->jsonResponse([
            'success' => true,
            'rootPageId' => $rootPageId,
            'languageId' => $languageId,
            'pages' => array_values($pagesByUid),
        ]);
    }

    /**
     * Durchläuft den Seitenbaum rekursiv und sammelt Standard-Seiten.
     * Shortcuts werden aufgelöst; doppelte UIDs nur einmal aufgenommen.
     *
     * @param array<string, mixed> $page
     * @param array<int, array<string, mixed>> $pagesByUid
     */
    private function collectPages(
        PageRepository $pageRepository,
        array $page,
        int $languageId,
        array &$pagesByUid
    ): void {
        $doktype = (int)($page['doktype'] ?? 0);

        if ($doktype === self::DOKTYPE_SHORTCUT) {
            $resolved = $pageRepository->resolveShortcutPage($page, false, true);
            if ($resolved !== [] && (int)($resolved['doktype'] ?? 0) === self::DOKTYPE_STANDARD) {
                $this->addStandardPage($pageRepository, $resolved, $languageId, $pagesByUid);
            }
        } elseif ($doktype === self::DOKTYPE_STANDARD) {
            $this->addStandardPage($pageRepository, $page, $languageId, $pagesByUid);
        }

        foreach ($this->fetchChildPages((int)$page['uid']) as $child) {
            // Sprach-Overlay für Kindseite anwenden (Default-Language-Record aus DB)
            if ($languageId > 0) {
                $overlaid = $pageRepository->getPageOverlay($child, $languageId);
                if ($overlaid !== []) {
                    $child = $overlaid;
                }
            }
            $this->collectPages($pageRepository, $child, $languageId, $pagesByUid);
        }
    }

    /**
     * @param array<string, mixed> $page
     * @param array<int, array<string, mixed>> $pagesByUid
     */
    private function addStandardPage(
        PageRepository $pageRepository,
        array $page,
        int $languageId,
        array &$pagesByUid
    ): void {
        $pageUid = (int)$page['uid'];
        if (isset($pagesByUid[$pageUid])) {
            return;
        }

        $pagesByUid[$pageUid] = [
            'uid' => $pageUid,
            'pid' => (int)$page['pid'],
            'title' => (string)($page['title'] ?? ''),
            'seo_title' => (string)($page['seo_title'] ?? ''),
            'description' => (string)($page['description'] ?? ''),
            'context' => $this->buildContextText($pageRepository, $pageUid, $languageId),
        ];
    }

    /**
     * Direkte Kindseiten (Default-Sprache), unabhängig vom FE-Gruppen-Zugriff.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchChildPages(int $parentId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Default-Restrictions: deleted, hidden, starttime/endtime
        $languageField = (string)($GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid');

        return $queryBuilder
            ->select(...self::PAGE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($parentId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $languageField,
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Fasst sichtbare Content-Elemente zu einem Fließtext für die KI zusammen
     * (header, subheader, bodytext – HTML entfernt).
     */
    private function buildContextText(
        PageRepository $pageRepository,
        int $pageUid,
        int $languageId
    ): string {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Default-Restrictions: deleted = 0, hidden = 0, starttime/endtime
        // transOrigPointerField: in älteren Installationen oft noch "l18n_parent"
        $transOrigPointerField = (string)($GLOBALS['TCA']['tt_content']['ctrl']['transOrigPointerField'] ?? 'l10n_parent');

        $result = $queryBuilder
            ->select('uid', 'header', 'subheader', 'bodytext', 'sys_language_uid', $transOrigPointerField)
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter([-1, 0], Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery();

        $parts = [];
        while ($row = $result->fetchAssociative()) {
            if ($languageId > 0) {
                $overlaid = $pageRepository->getLanguageOverlay('tt_content', $row);
                if ($overlaid === null) {
                    continue;
                }
                $row = $overlaid;
            }

            foreach (['header', 'subheader', 'bodytext'] as $field) {
                $plain = $this->toPlainText((string)($row[$field] ?? ''));
                if ($plain !== '') {
                    $parts[] = $plain;
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Entfernt HTML und normalisiert Whitespace.
     */
    private function toPlainText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s*\n\s*/', "\n", $value) ?? $value;

        return trim($value);
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
