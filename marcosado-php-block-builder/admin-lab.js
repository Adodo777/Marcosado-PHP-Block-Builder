jQuery(document).ready(function($) {
    var $textarea = $('#block-code-editor');

    if ($textarea.length && typeof mpbbSettings !== 'undefined') {
        // Activer le retour à la ligne (lineWrapping) pour éviter l'étirement sur les longues lignes (ex: URLs)
        var settings = $.extend(true, {}, mpbbSettings);
        if (settings.codemirror) {
            settings.codemirror.lineWrapping = true;
        }

        // Initialisation de CodeMirror via l'API WordPress avec les réglages personnalisés
        var editor = wp.codeEditor.initialize($textarea, settings);

        // Optionnel : Forcer la mise à jour du textarea avant la soumission du formulaire
        $(document).on('submit', 'form', function() {
            if (editor && editor.codemirror) {
                editor.codemirror.save();
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const copyBtn = document.getElementById('bm-copy-ai-prompt');
    if (!copyBtn) return;
    
    const aiPromptText = `Tu es un assistant IA spécialisé pour WordPress et le plugin "Marcosado PHP Block Builder".
Génère le code complet d'un bloc personnalisé en respectant strictement ces consignes :

1. DÉCLARATION DES ATTRIBUTS (OPTIONNEL) :
Si le bloc a besoin de champs éditables (titre, image, couleurs, etc.), déclare le tableau $bm_attributes dans le tout premier bloc PHP fermé de ton code :
<?php
// Optionnel : ne le mettez que si vous avez besoin de paramètres dynamiques
$bm_attributes = [
    'cle_attribut' => [
        'type' => 'text'|'textarea'|'number'|'boolean'|'color'|'image'|'select'|'repeater',
        'label' => 'Label affiché',
        'default' => 'valeur_par_defaut', // pour select : 'val1:Label1,val2:Label2'
        'section' => 'Général', // optionnel
        'sub_fields' => json_encode(['sous_cle' => ['type' => 'text', 'default' => '']]) // requis uniquement si type='repeater'
    ],
];
?>

2. RENDU HTML / PHP :
- Les variables déclarées dans les attributs sont automatiquement utilisables directement par leur nom de clé (ex: $cle_attribut).
- Les classes CSS de mise en forme doivent utiliser le préfixe Tailwind "tw-" (ex: tw-bg-slate-900, tw-text-white).
- Pour les valeurs dynamiques de couleur (comme les attributs de type "color"), écris-les en CSS inline via l'attribut HTML "style" (ex: style="color: <?php echo $cle_couleur; ?>;") pour éviter les bugs de valeurs arbitraires Tailwind.
- Pour inclure des icônes vectorielles Lucide Icons, utilise la balise suivante : <i data-lucide="nom-icone" class="tw-w-5 tw-h-5"></i>.
- N'écris AUCUN commentaire d'en-tête "Block Name" au début du fichier, le plugin s'occupe de le générer automatiquement.`;

    copyBtn.addEventListener('click', function() {
        const descriptionInput = document.getElementById('bm-ai-description');
        let userDescription = '';
        if (descriptionInput && descriptionInput.value.trim() !== '') {
            userDescription = "\n\nDESCRIPTION DU BLOC SOUHAITÉ :\n" + descriptionInput.value.trim() + "\n";
        }

        const finalPrompt = aiPromptText + userDescription;

        navigator.clipboard.writeText(finalPrompt).then(function() {
            const oldText = copyBtn.innerHTML;
            copyBtn.innerHTML = '✅ Copié !';
            copyBtn.style.color = '#008a20';
            setTimeout(function() {
                copyBtn.innerHTML = oldText;
                copyBtn.style.color = '';
            }, 2000);
        }).catch(function(err) {
            alert('Impossible de copier le texte : ', err);
        });
    });
});
