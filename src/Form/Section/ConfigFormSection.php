<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form\Section;

use Laminas\Form\Form;

/**
 * One logical group of fields on the Search Assistant AI admin configuration form.
 *
 * Implementations add their elements directly to the host form (no Laminas
 * Fieldset wrapping) so the form's data shape stays flat — controllers
 * iterate `$form->getElements()` by name and persist into Omeka settings
 * keyed by `search_assistant_*`. A nested Fieldset would prefix every name and
 * break the persistence contract.
 *
 * Each section also owns the input-filter spec for the fields it adds;
 * `ConfigForm::getInputFilterSpecification()` merges them.
 */
interface ConfigFormSection
{
    /** Append this section's elements to the host form. */
    public function addTo(Form $form): void;

    /**
     * Validation/filter specification for this section's fields, keyed by
     * field name. Merged into the form's full spec by ConfigForm.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getInputFilterSpecification(): array;
}
