<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Document\JsonapiDocument;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Serializer\JoomlaSerializer;
use Tobscure\JsonApi\Collection;
use Tobscure\JsonApi\Resource;

/**
 * The answers of a REST controller that writes through a core service: the record or the list as a JSON:API document,
 * the service's refusals as errors (each with its JSON pointer, the first refusal's status), the keys a body does not
 * take as meta.warnings (code not_a_field), and the ids the route names.
 *
 * @since  2.3.0
 */
trait AnswersFromServices
{
    /**
     * Put a record or a list in the answer, with its status and the warnings of the request.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $data A record's attributes (with its id), or a list of them.
     * @param list<array<string, string>> $warnings The warnings: code, title and pointer.
     *
     * @return static
     *
     * @since  2.3.0
     */
    protected function answer(array $data, int $status = 200, array $warnings = [])
    {
        $document = $this->app->getDocument();

        if (!$document instanceof JsonapiDocument) {
            throw new Save('The answer could not be written.', 500);
        }

        $serializer = new JoomlaSerializer($this->contentType);
        $document->setData(array_is_list($data) && ($data === [] || \is_array($data[0]))
            ? new Collection(array_map(static fn (array $row): object => (object) $row, $data), $serializer)
            : new Resource((object) $data, $serializer));
        $this->app->setHeader('status', (string) $status, true);

        if ($warnings !== []) {
            $document->addMeta('warnings', $warnings);
        }

        return $this;
    }

    /**
     * Answer the refusals: the first refusal's status, one error each with its JSON pointer; nothing was written.
     *
     * @param list<Refusal> $refusals The refusals.
     *
     * @return static
     *
     * @since  2.3.0
     */
    protected function refuse(array $refusals)
    {
        $document = $this->app->getDocument();

        if (!$document instanceof JsonapiDocument) {
            throw new Save('The answer could not be written.', 500);
        }

        $errors = [];

        foreach ($refusals as $refusal) {
            $sentence = $refusal->placed();
            $errors[] = ['title' => str_ends_with($sentence, 'Nothing was saved.') ? $sentence : $sentence . ' Nothing was saved.', 'code' => $refusal->status]
                + ($refusal->pointer === '' ? [] : ['source' => ['pointer' => $refusal->pointer]]);
        }

        $document->setErrors($errors);
        $this->app->setHeader('status', (string) ($refusals[0]->status ?? Refusal::INVALID), true);

        return $this;
    }

    /**
     * The JSON object the request sent; a body of another shape answers 422.
     *
     * @param string $example A body the route takes, for the sentence.
     *
     * @throws Save When the body is not a JSON object (422).
     *
     * @since  2.3.0
     */
    protected function bodyObject(string $example): array
    {
        $body = json_decode((string) $this->input->json->getRaw(), true);

        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new Save('Send a JSON object, ' . $example . '. Nothing was saved.', 422);
        }

        return $body;
    }

    /**
     * The warnings of the keys a body sends that the route does not take: they are left out.
     *
     * @param array $body The body.
     * @param list<string> $keys The keys the route takes.
     * @param string $what What the body is, for the sentence ("a value").
     *
     * @return list<array<string, string>>
     *
     * @since  2.3.0
     */
    protected function unknownKeys(array $body, array $keys, string $what, string $pointer = ''): array
    {
        $warnings = [];

        foreach (array_diff(array_map('strval', array_keys($body)), $keys) as $key) {
            $warnings[] = ['code' => 'not_a_field', 'title' => $key . ' is not a key of ' . $what . '; it was left out.', 'pointer' => $pointer . '/' . $key];
        }

        return $warnings;
    }

    /**
     * A translations object, {"en_gb": {"name": "White"}}, as the flat keys the services take (name_en_gb): a language
     * that is not one of the shop's content languages is refused, a text the object does not take is a warning.
     *
     * @param mixed $translations The object sent.
     * @param list<string> $texts The texts each language takes.
     *
     * @return array{0: array<string, string>, 1: list<Refusal>, 2: list<array<string, string>>} The fields, the refusals, the warnings.
     *
     * @since  2.3.0
     */
    protected function translationFields(mixed $translations, array $texts, string $pointer = '/translations'): array
    {
        if (!\is_array($translations) || ($translations !== [] && array_is_list($translations))) {
            return [[], [new Refusal(code: 'invalid', sentence: 'send an object of language to texts, {"en_gb": {"name": "White"}}.', pointer: $pointer)], []];
        }

        $languages = array_map(static fn (string $tag): string => MultilingualHelper::normaliseTag(tag: $tag), MultilingualHelper::getLanguageTags());
        $fields = $refusals = $warnings = [];

        foreach ($translations as $tag => $values) {
            $key = MultilingualHelper::normaliseTag(tag: (string) $tag);

            if (!\is_array($values) || !\in_array($key, $languages, true)) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send the texts of a content language of the shop (' . implode(', ', $languages) . ').', pointer: $pointer . '/' . $tag);

                continue;
            }

            foreach ($values as $text => $value) {
                if (!\in_array((string) $text, $texts, true)) {
                    $warnings[] = ['code' => 'not_a_field', 'title' => $text . ' is not a text the record takes; it was left out.', 'pointer' => $pointer . '/' . $tag . '/' . $text];
                } elseif (!\is_string($value)) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send text.', pointer: $pointer . '/' . $tag . '/' . $text);
                } else {
                    $fields[$text . '_' . $key] = trim($value);
                }
            }
        }

        return [$fields, $refusals, $warnings];
    }

    /**
     * One of the ids the route names.
     *
     * @since  2.3.0
     */
    protected function routeId(string $name): int
    {
        // A POST's route variables arrive with its posted data, as the order operations read them.
        $bag = strtoupper((string) $this->input->getMethod()) === 'POST' ? $this->input->post : $this->input;

        return max(0, (int) RequestScalar::int($bag, $name, 0));
    }
}
