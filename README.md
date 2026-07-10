# 🛠 Marcosado PHP Block Builder

**Marcosado PHP Block Builder** is a powerful WordPress plugin designed for developers. It allows you to create custom Gutenberg blocks autonomously using only PHP, Tailwind CSS, and dynamic attributes (sidebar options). Write your logic in PHP and see the results instantly in the editor.

---

## 🚀 Key Features

- **PHP-First Development**: Create perfectly functional Gutenberg blocks using standard PHP syntax.
- **Integrated Code Lab**: A dedicated admin interface ("Blocks Lab") to write, edit, and manage your blocks without leaving the dashboard.
- **Inline Dynamic Attributes**: Declare your attributes via `$bm_attributes = [...]` directly in the first PHP block of your code. The plugin syncs them automatically!
- **Bidirectional Gutenberg ↔ Elementor Bridge**: Edit your pages in the editor of your choice. Data (attributes) migrate instantly from one format to another without data loss.
- **Intelligent Pre-warming**: Elementor data is generated in the background on every Gutenberg save, even if Elementor isn't installed yet. The day you activate it, your existing pages will display instantly in Elementor!
- **Non-Intrusive Coexistence**: Thanks to surgical native WP block parsing (`parse_blocks`), only your custom blocks are modified. Your native paragraphs, images, and widgets remain 100% intact.
- **Tailwind CSS Included**: Native support for Tailwind CSS with a `tw-` prefix to avoid style conflicts with your theme.
- **Integrated Lucide Icons**: Load and display modern, lightweight vector icons on the front-end and in the editor.

---

## 🤖 AI Prompt: Generate Blocks in One Click

Copy and paste the text below into your favorite AI (ChatGPT, Claude, Gemini, etc.) to generate a block that is 100% compatible with Marcosado PHP Block Builder:

```markdown
Tu es un assistant IA spécialisé pour WordPress et le plugin "Marcosado PHP Block Builder".
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
- N'écris AUCUN commentaire d'en-tête "Block Name" au début du fichier, le plugin s'occupe de le générer automatiquement.
```
*(The AI prompt above is kept in French as the original audience might use French prompts, but the tool accepts standard WordPress logic).*

---

## 📖 Getting Started

### 1. Installation
- Download the plugin and upload it to your `wp-content/plugins/` folder.
- Activate the plugin via the WordPress "Plugins" menu.

### 2. Declare attributes and code in PHP
Configure your attributes directly in your block's code, within the **first closed PHP block**:

```php
<?php
$bm_attributes = [
    'titre'   => ['type' => 'text',     'label' => 'Main Title',       'default' => 'My Title'],
    'couleur' => ['type' => 'color',    'label' => 'Color',            'default' => '#3B82F6'],
    'visible' => ['type' => 'boolean',  'label' => 'Show button',      'default' => 'true'],
    'photo'   => ['type' => 'image',    'label' => 'Background image', 'default' => ''],
    'choix'   => ['type' => 'select',   'label' => 'Option',           'default' => 'opt1:Option 1,opt2:Option 2'],
];
?>

<div class="tw-p-6 tw-rounded-lg" style="background-color: <?php echo esc_attr($couleur); ?>;">
    <h2 class="tw-text-white tw-text-2xl"><?php echo esc_html($titre); ?></h2>
    <?php if ($visible) : ?>
        <button class="tw-bg-white tw-text-black tw-px-4 tw-py-2 tw-mt-4">Click here</button>
    <?php endif; ?>
</div>
```

---

## 🔄 The Gutenberg ↔ Elementor Bridge

The plugin integrates a robust and transparent synchronization:

### 1. Coexistence and multiple instances
- If you use the same block multiple times (e.g., 3 identical CTA blocks), the plugin handles their sync via **sequential indexing** (order of appearance). Each instance retains its own settings when switching from one editor to another.
- Default WordPress blocks (`core/paragraph`, etc.) and third-party widgets are completely ignored by the conversion script: they will **never be altered or overwritten**.

### 2. Format normalization
The plugin translates the specifics of each builder:
- **Booleans** go from PHP/Gutenberg format (`true`/`false`) to Elementor switcher format (`'yes'`/`''`).
- **Images** smartly handle Elementor's empty arrays `['url' => '', 'id' => '']` to prevent broken or corrupted URLs.

---

## 🎨 Lucide Icons

Lucide Icons are optimally pre-registered on the front-end and in the editor. To display an icon, simply use the `data-lucide` attribute:

```html
<i data-lucide="heart" class="tw-w-6 tw-h-6 tw-text-red-500"></i>
<i data-lucide="star" class="tw-w-5 tw-h-5"></i>
```

The rendering script automatically initializes the icons as soon as the DOM is loaded (`DOMContentLoaded`).

---

## 🎨 Styling with Tailwind

To use Tailwind CSS, simply apply the `tw-` prefix to your classes:

```php
<div class="tw-bg-slate-900 tw-p-10 tw-rounded-xl">
    <h1 class="tw-text-white tw-text-4xl tw-font-bold">Hello World</h1>
    <p class="tw-text-slate-400">Powered by Marcosado PHP Block Builder</p>
</div>
```

---

## 💡 Best Practices & Tips

### 🚀 Tailwind CSS and Arbitrary Values
Sometimes Tailwind classes using arbitrary values in brackets (e.g., `tw-bg-[#ff2d8a]`) may not work correctly with the `tw-` prefix.
- **Recommendation**: For all custom colors or values, use inline CSS via the `style` attribute.
- **Example**: `<div style="background-color: #ff2d8a;">...</div>` instead of `tw-bg-[#ff2d8a]`.

### 🔄 Dynamic Content & WordPress
Since the rendering is in PHP, you can use the full power of WordPress:
- **Native Functions**: Use `get_the_ID()`, `get_post_meta()`, etc.
- **ACF (Advanced Custom Fields)**: Easily integrate your custom fields with `get_field('my_field')` directly in your PHP blocks!

---

## 📁 Project Structure

- `marcosado-php-block-builder.php`: Main plugin entry point.
- `includes/`: Contains modular classes for DB, Admin, Gutenberg, Elementor, and Sync logic.
- `editor-blocks.js`: Handles placeholder rendering on the Gutenberg editor side.
- `admin-lab.js`: CodeMirror code editor for the dashboard.
- `/assets/lucide.min.js`: Lucide icons library.
- `/wp-content/uploads/marcosado-php-block-builder/`: **Critical Folder**. This is where all your custom PHP block files are stored on the server.

---

## ⚠️ Important Note
> [!WARNING]
> The directory `/wp-content/uploads/marcosado-php-block-builder/` is the storage engine of this plugin. Deleting files from this folder will delete the corresponding blocks from your website. Always keep backups of your code!

---

**Developed with ❤️ by marcosado**
