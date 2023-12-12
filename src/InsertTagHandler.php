<?php

declare(strict_types=1);

namespace Terminal42\InsertTagsBundle;

use Codefog\HasteBundle\Formatter;
use Contao\Database;
use Contao\FrontendUser;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Haste\Util\Format;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

class InsertTagHandler
{
    private array $cache = [];

    public function __construct(
        private Connection $connection,
        private Parser $parser,
        private RequestStack $requestStack,
        private Security $security,
        private Formatter|null $formatter,
    ) {
    }

    /**
     * Parse the insert tag.
     */
    public function parseInsertTag(string $tag): ?string
    {
        $chunks = explode('::', $tag);

        if ('custom' !== $chunks[0]) {
            return null;
        }

        $record = $this->getTagRecord($chunks[1]);

        if (null === $record) {
            return null;
        }

        $user = $this->getFrontendUser();
        $pageModel = $this->getPageModel();

        // Generate the evaluation tokens
        $tokens = [
            'member' => $user,
            'page' => $pageModel,
        ];

        // Generate the member replacement tokens
        if (null !== $user) {
            foreach ($user->getData() as $k => $v) {
                if ($this->formatter) {
                    $tokens['member_'.$k] = $this->formatter->dcaValue('tl_member', $k, $v);
                } else {
                    $tokens['member_'.$k] = Format::dcaValue('tl_member', $k, $v);
                }
            }
        }

        // Generate the page replacement tokens
        if (null !== $pageModel) {
            foreach ($pageModel->row() as $k => $v) {
                if ($this->formatter) {
                    $tokens['page_'.$k] = $this->formatter->dcaValue('tl_page', $k, $v);
                } else {
                    $tokens['page_'.$k] = Format::dcaValue('tl_page', $k, $v);
                }
            }
        }

        // Return the "replacement not" if tag is found but does not validate
        if (!$this->isTagValid($record)) {
            return $this->parser->parse((string) $record['replacementNot'], $tokens);
        }

        return $this->parser->parse((string) $record['replacement'], $tokens);
    }

    /**
     * Get the tag record.
     */
    private function getTagRecord(string $tag): ?array
    {
        if (!\array_key_exists($tag, $this->cache)) {
            $this->cache[$tag] = $this->connection->fetchAssociative('SELECT * FROM tl_inserttags WHERE tag=?', [$tag]);
        }

        return $this->cache[$tag] ?: null;
    }

    /**
     * Check if a tag should be applied (rules, pages).
     */
    private function isTagValid(array $record): bool
    {
        if (!$this->validateProtection($record) || !$this->validateLimitPages($record)) {
            return false;
        }

        return true;
    }

    /**
     * Validate the protection.
     */
    private function validateProtection(array $record): bool
    {
        if (!$record['protected']) {
            return true;
        }

        $groups = StringUtil::deserialize($record['groups']);

        // There are no groups
        if (!\is_array($groups) || 0 === \count($groups)) {
            return false;
        }

        $groups = array_map('\intval', $groups);
        $user = $this->getFrontendUser();

        // Allow anonymous users if the "guest" group is allowed
        if (null === $user) {
            return \in_array(-1, $groups, true);
        }

        $userGroups = StringUtil::deserialize($user->groups);

        // Member is not in the group
        if (!\is_array($userGroups) || 0 === \count($userGroups) || 0 === \count(array_intersect($groups, $userGroups))) {
            return false;
        }

        return true;
    }

    /**
     * Validate the limit pages.
     */
    private function validateLimitPages(array $record): bool
    {
        if (!$record['limitpages']) {
            return true;
        }

        // The page model is not available
        if (($request = $this->requestStack->getCurrentRequest()) === null) {
            return false;
        }

        $pageIds = StringUtil::deserialize($record['pages']);

        if (!\is_array($pageIds)) {
            return false;
        }

        // Validate only if there is a page model
        if (($currentPage = $this->getPageModel($request)) !== null) {
            $pageIds = array_map('\intval', $pageIds);
            $currentPageId = (int) $currentPage->id;

            if (!\in_array($currentPageId, $pageIds, true)) {
                if (!$record['includesubpages']) {
                    return false;
                }

                $parentIds = array_map('\intval', Database::getInstance()->getParentRecords($currentPageId, 'tl_page'));

                if (0 === \count(array_intersect($pageIds, $parentIds))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get page model from the request.
     */
    private function getPageModel(?Request $request = null): ?PageModel
    {
        if (null === $request) {
            $request = $this->requestStack->getCurrentRequest();
        }

        if (!$request->attributes->has('pageModel')) {
            return null;
        }

        $page = $request->attributes->get('pageModel');

        if ($page instanceof PageModel) {
            return $page;
        }

        if (
            isset($GLOBALS['objPage'])
            && $GLOBALS['objPage'] instanceof PageModel
            && (int) $GLOBALS['objPage']->id === (int) $page
        ) {
            return $GLOBALS['objPage'];
        }

        return PageModel::findByPk($page);
    }

    /**
     * Get the frontend user.
     */
    private function getFrontendUser(): ?FrontendUser
    {
        $user = $this->security->getUser();

        return $user instanceof FrontendUser ? $user : null;
    }
}
