/*
 * Search Assistant AI module: /admin/module/configure?id=SearchAssistantAI page behaviour.
 *
 * Shows the subgroup matching the selected Vector Store Backend and LLM
 * Provider (subgroup id "search-assistant-subgroup-<value>") and hides the other
 * subgroups of that section, so backends and providers added by another
 * module only need a matching subgroup id. Rendered by
 * SearchAssistantAI\Form\ConfigFormRenderer.
 */

document.addEventListener('DOMContentLoaded', function () {
    function bindSubgroups(selectId, sectionId) {
        var select = document.getElementById(selectId);
        var section = document.getElementById(sectionId);
        if (!select || !section) {
            return;
        }
        var sync = function () {
            section.querySelectorAll('.search-assistant-config-subgroup').forEach(function (subgroup) {
                subgroup.style.display = subgroup.id === 'search-assistant-subgroup-' + select.value ? '' : 'none';
            });
        };
        select.addEventListener('change', sync);
        sync();
    }

    bindSubgroups('search_assistant_vector_store_backend', 'search-assistant-section-vector-store');
    bindSubgroups('search_assistant_llm_provider', 'search-assistant-section-llm');
});
