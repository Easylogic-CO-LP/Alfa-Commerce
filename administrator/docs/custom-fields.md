# Σύστημα Custom Fields του Alfa — Οδηγός Developer

## 1. Τι είναι το σύστημα

Ο `com_alfa` έχει δικό του σύστημα custom fields (πίνακας `#__alfa_fields`), παράλληλο με το core `com_fields` του Joomla. Δουλεύει όπως το core, αλλά με δικά μας events, helpers και plugins για να μην ανακατεύεται.

Κάθε custom field στη DB έχει:
- `type` — π.χ. `text`, `textarea`, `tel`
- `context` — σε ποιο entity κολλάει (π.χ. `com_alfa.product`, `com_alfa.order`)
- `field_name`, `field_label`, `field_description`, `default_value`, `required`
- `fieldparams` — extra params ειδικά για τον τύπο

Κάθε `type` το χειρίζεται ένας plugin στο group `alfa-fields`:

```
plugins/alfa-fields/text/
plugins/alfa-fields/textarea/
plugins/alfa-fields/tel/
```

---

## 2. Οι δύο φάσεις ζωής ενός field

| Φάση | Πότε | Τι παράγει | Ποιος |
|---|---|---|---|
| **A — INPUT** | Όταν ο χρήστης γράφει (edit form) | Το `<input>` που βλέπει | `prepareDom()` |
| **B — OUTPUT** | Όταν η εφαρμογή δείχνει αποθηκευμένη τιμή | HTML εμφάνισης | `tmpl/<type>.php` |

---

## 3. Φάση A — `prepareDom()` (INPUT)

### Τι κάνει
Παίρνει τον ορισμό του field από τη DB και προσθέτει ένα `<field>` XML node στη φόρμα. Το Joomla Form το μετατρέπει σε HTML `<input>`.

### Ροή
```
User ανοίγει edit form
  → FieldsHelper::prepareForm()                 [FieldsHelper.php:97]
    → για κάθε field του context
      → dispatch 'onAlfaFieldsPrepareDom'       [FieldsHelper.php:249]
        → το plugin απαντάει → prepareDom()
          → προσθέτει <field name="..." type="text" ...> στο fieldset
  → $form->renderFieldset() → HTML input
```

### Παράδειγμα — `plugins/alfa-fields/tel/src/Extension/Tel.php`

```php
public function prepareDom($event)
{
    $node = parent::prepareDom($event);
    if ($node === null) return null;

    $node->setAttribute('type', 'text');
    $node->setAttribute('inputmode', 'tel');
    $node->setAttribute('autocomplete', 'tel');
    $node->setAttribute('validate', 'alfatel');

    return $node;
}
```

### Πού φαίνεται σήμερα
**Ενεργό παντού** όπου καλείται `FieldsHelper::prepareForm()` — admin edit οθόνες και όποιο frontend form το καλέσει.

---

## 4. Φάση B — `tmpl/<type>.php` (OUTPUT)

### Διπλός ρόλος — ΠΟΛΥ ΣΗΜΑΝΤΙΚΟ

Το αρχείο κάνει **δύο πράγματα ταυτόχρονα**:

#### Ρόλος 1 — Marker (ενεργός σήμερα)
Η ύπαρξη του αρχείου δηλώνει ότι ο plugin υποστηρίζει τον συγκεκριμένο τύπο. Από `FieldsPlugin.php:403`:

```php
foreach (Folder::files($root . '/tmpl', '\.php$') as $layout) {
    $types[] = str_replace('.php', '', $layout);
}
```

- Υπάρχει `tmpl/tel.php` → `isTypeSupported('tel')` = true → `prepareDom` τρέχει
- Αν το σβήσεις → false → **χαλάει και η φόρμα input**

**Ο ρόλος αυτός δουλεύει τώρα — ακόμα και άδειο αρχείο τον καλύπτει.**

#### Ρόλος 2 — Display renderer (ΝΕΚΡΟΣ σήμερα)
Το περιεχόμενο του αρχείου λέει πώς εμφανίζεται η αποθηκευμένη τιμή.

`plugins/alfa-fields/tel/tmpl/tel.php`:

```php
<?php
defined('_JEXEC') or die;
$value = $field->value ?? '';
if ($value === '' || $value === null) return;

echo '<a href="tel:' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
    . '</a>';
```

**Ο κώδικας αυτός δεν τρέχει πουθενά στην εφαρμογή.** Είναι έτοιμος αλλά κανείς δεν τον καλεί.

### Επιβεβαίωση από repo grep
- `FieldsPlugin.php` → μέθοδος `prepareField` σχολιασμένη (lines 93-100, 195-216)
- `FieldsPlugin.php:64` → εγγραφή στο `getSubscribedEvents()` σχολιασμένη
- `FieldsHelper.php:641` → dispatch σχολιασμένο
- Κανένα component tmpl δεν κάνει `include 'plugins/alfa-fields/...'`

---

## 5. Τι δουλεύει / τι όχι — σύνοψη

| Λειτουργία | Κατάσταση |
|---|---|
| Δημιουργία custom field στη διαχείριση | ✅ |
| Εμφάνιση input σε edit form (`prepareDom`) | ✅ |
| Αποθήκευση τιμής στη DB | ✅ |
| Εμφάνιση αποθηκευμένης τιμής (`tmpl`) | ❌ νεκρό pipeline |

---

## 6. Ο σχολιασμένος κώδικας — τι είναι, τι να κάνεις

Στα `FieldsPlugin.php` και `FieldsHelper.php` υπάρχει **πολύς σχολιασμένος κώδικας**. Δεν είναι σκουπίδια — είναι το **αυθεντικό core Joomla pattern** κρατημένο ως blueprint.

### Πού είναι:
- `FieldsPlugin.php:63-67` — event registrations
- `FieldsPlugin.php:93-100` — `prepareField(PrepareFieldEvent $event)` wrapper
- `FieldsPlugin.php:122-185` — `onCustomFieldsGetTypes()` (αντικαταστάθηκε από `getSupportedFieldTypes()`)
- `FieldsPlugin.php:195-216` — **`onCustomFieldsPrepareField()` που κάνει `include` το tmpl** ← η καρδιά του display pipeline
- `FieldsPlugin.php:343-395` — `onContentPrepareForm` + `getFormPath` (per-type `params/*.xml`)
- `FieldsHelper.php:456-700` — ολόκληρο το core Joomla `FieldsHelper` ως reference (περιέχει `getFields($prepareValue)` και `render()`)

### Οδηγία
**ΜΗΝ κάνεις απλό uncomment.** Ο σχολιασμένος κώδικας χρησιμοποιεί core Joomla event names (`onCustomFieldsPrepareField`). Ο Alfa έχει δικά του (`onAlfaFieldsPrepareField`).

→ Χρησιμοποίησε τον σχολιασμένο κώδικα ως **blueprint**: αντέγραψε τη λογική, **άλλαξε event names** σε `onAlfaFieldsPrepareField`, κράτα `include` + `ob_start/ob_get_clean`.

---

## 7. Έτοιμα Event skeletons (disabled)

Στο `administrator/components/com_alfa/src/Event/Fields/`:

```
PrepareDomEvent.php              ✅ ενεργό, σωστό namespace
_AbstractPrepareFieldEvent.php   ⚠️ disabled (underscore + λάθος namespace)
_PrepareFieldEvent.php           ⚠️ disabled
_BeforePrepareFieldEvent.php     ⚠️ disabled (optional)
_AfterPrepareFieldEvent.php      ⚠️ disabled (optional)
_GetTypesEvent.php               ⚠️ disabled (δεν χρειάζεται)
```

Τα underscore αρχεία έχουν **λάθος namespace** (`Joomla\CMS\Event\CustomFields` αντί `Alfa\Component\Alfa\Administrator\Event\Fields`).

### Ενεργοποίηση
1. Rename `_PrepareFieldEvent.php` → `PrepareFieldEvent.php`
2. Rename `_AbstractPrepareFieldEvent.php` → `AbstractPrepareFieldEvent.php`
3. Στα δύο αρχεία, διόρθωσε namespace:

   ```php
   // ΛΑΘΟΣ:
   namespace Joomla\CMS\Event\CustomFields;
   // ΣΩΣΤΟ (ίδιο με PrepareDomEvent):
   namespace Alfa\Component\Alfa\Administrator\Event\Fields;
   ```

4. Το ίδιο για `_BeforePrepareFieldEvent` / `_AfterPrepareFieldEvent` **ΜΟΝΟ αν** θέλεις before/after hooks. Για βασικό display ΔΕΝ χρειάζονται — skip.

---

## 8. Ενεργοποίηση display pipeline — βήμα βήμα

### Βήμα 1 — Event class
Rename + namespace fix στα `_AbstractPrepareFieldEvent.php` και `_PrepareFieldEvent.php` (βλ. §7).

### Βήμα 2 — `FieldsPlugin.php`

**(α)** `administrator/components/com_alfa/src/Plugin/FieldsPlugin.php`, `getSubscribedEvents()`:

```php
return [
    'onAlfaFieldsPrepareDom'   => 'prepareDom',
    'onAlfaFieldsPrepareField' => 'prepareField',
];
```

**(β)** Πρόσθεσε μέθοδο στην ίδια class:

```php
public function prepareField($event)
{
    $field = $event->getField();

    if (!$this->isTypeSupported($field->type)) {
        return;
    }

    $path = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name
          . '/tmpl/' . $field->type . '.php';

    if (!is_file($path)) {
        return;
    }

    ob_start();
    include $path;   // μέσα: $field, $event
    $event->addResult(ob_get_clean());
}
```

### Βήμα 3 — Helper στο `FieldsHelper.php`

Πρόσθεσε στο `administrator/components/com_alfa/src/Helper/FieldsHelper.php`:

```php
use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareFieldEvent;
use Joomla\Event\DispatcherInterface;

public static function renderFieldValue($field, $item = null): string
{
    $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);
    PluginHelper::importPlugin('alfa-fields');

    $event = new PrepareFieldEvent('onAlfaFieldsPrepareField', [
        'subject' => $field,
        'item'    => $item,
        'context' => $field->context ?? '',
    ]);

    $dispatcher->dispatch($event->getName(), $event);

    $result = $event->getArgument('result', []);
    return is_array($result) ? implode('', $result) : (string) $result;
}
```

### Βήμα 4 — Κλήση από component tmpls

Όπου θες να εμφανίσεις τιμές (π.χ. order view, product detail, invoice, email):

```php
<?php $fields = FieldsHelper::getFields('com_alfa.order', $item); ?>
<?php foreach ($fields as $field): ?>
    <dt><?php echo htmlspecialchars($field->field_label); ?></dt>
    <dd><?php echo FieldsHelper::renderFieldValue($field, $item); ?></dd>
<?php endforeach; ?>
```

Από εδώ και πέρα, το `plugins/alfa-fields/tel/tmpl/tel.php` θα τρέξει και θα εμφανίσει το τηλέφωνο ως `<a href="tel:...">`.

---

## 9. Πώς προσθέτεις νέο τύπο — 3 επίπεδα

### Επίπεδο 1 — Wrapper γύρω από built-in Joomla field
**Πότε:** Υπάρχει ήδη στο Joomla (`color`, `calendar`, `user`, `media`, `editor`, `list`, `subform`, `tag`, `category`, `checkboxes`, `radio`).

**Δομή:**
```
plugins/alfa-fields/color/
├── color.xml
├── services/provider.php
├── src/Extension/Color.php
└── tmpl/color.php
```

`Color.php`:

```php
public function prepareDom($event)
{
    $node = parent::prepareDom($event);
    if ($node === null) return null;

    $node->setAttribute('type', 'color');
    return $node;
}
```

### Επίπεδο 2 — Δικό σου UI
**Πότε:** Δεν υπάρχει στο Joomla (product picker, signature pad, map marker).

**Δομή:**
```
plugins/alfa-fields/productpicker/
├── productpicker.xml
├── services/provider.php
├── src/
│   ├── Extension/Productpicker.php       ← prepareDom
│   └── Field/ProductpickerField.php      ← FormField class
└── tmpl/productpicker.php
```

`Productpicker.php`:

```php
$node->setAttribute('type', 'productpicker');
FormHelper::addFieldPrefix('Joomla\\Plugin\\AlfaFields\\Productpicker\\Field');
```

`ProductpickerField` extends `FormField`, υλοποιεί `getInput()` και γυρνάει HTML/JS.

### Επίπεδο 3 — Μόνο info/display
Χρησιμοποιείς `type=note` στο `prepareDom` με HTML στο `description`. Γρήγορο, χωρίς input.

---

## 9.1 — Πλήρες παράδειγμα Επιπέδου 1: `color` field

Σενάριο: θέλουμε custom field τύπου `color` που στις edit οθόνες εμφανίζει έτοιμο color picker του browser, και (όταν ενεργοποιηθεί το display pipeline) στο frontend εμφανίζει swatch + hex.

### Αρχεία

```
plugins/alfa-fields/color/
├── color.xml                               ← manifest
├── services/provider.php                   ← DI registration
├── src/
│   └── Extension/
│       └── Color.php                       ← FieldsPlugin subclass
├── tmpl/
│   └── color.php                           ← marker + display template
└── language/
    └── en-GB/
        ├── plg_alfa-fields_color.ini
        └── plg_alfa-fields_color.sys.ini
```

### 1. `color.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<extension type="plugin" group="alfa-fields" method="upgrade">
    <name>plg_alfa-fields_color</name>
    <author>Easylogic</author>
    <creationDate>2026-04</creationDate>
    <copyright>(C) 2024-2026 Easylogic CO LP</copyright>
    <license>GNU General Public License version 3 or later</license>
    <version>1.0.0</version>
    <description>PLG_ALFA_FIELDS_COLOR_XML_DESCRIPTION</description>
    <namespace path="src">Joomla\Plugin\AlfaFields\Color</namespace>
    <files>
        <folder plugin="color">services</folder>
        <folder>src</folder>
        <folder>tmpl</folder>
        <folder>language</folder>
    </files>
    <languages>
        <language tag="en-GB">language/en-GB/plg_alfa-fields_color.ini</language>
        <language tag="en-GB">language/en-GB/plg_alfa-fields_color.sys.ini</language>
    </languages>
    <config>
        <fields name="params">
            <fieldset name="basic">
                <field name="format" type="list" default="hex"
                       label="PLG_ALFA_FIELDS_COLOR_FORMAT_LABEL">
                    <option value="hex">HEX (#ff0000)</option>
                    <option value="rgb">RGB</option>
                </field>
                <field name="show_swatch" type="radio" class="switcher" default="1"
                       label="PLG_ALFA_FIELDS_COLOR_SWATCH_LABEL">
                    <option value="0">JNO</option>
                    <option value="1">JYES</option>
                </field>
            </fieldset>
        </fields>
    </config>
</extension>
```

### 2. `services/provider.php`

```php
<?php
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\AlfaFields\Color\Extension\Color;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = new Color(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('alfa-fields', 'color')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
```

### 3. `src/Extension/Color.php`

```php
<?php

namespace Joomla\Plugin\AlfaFields\Color\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\FieldsPlugin;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

final class Color extends FieldsPlugin implements SubscriberInterface
{
    public function prepareDom($event)
    {
        $node = parent::prepareDom($event);
        if ($node === null) {
            return null;
        }

        // Ο browser δίνει native color picker με type="color".
        $node->setAttribute('type', 'color');

        // Logical default αν ο admin δεν όρισε τίποτα.
        if (!$node->getAttribute('default')) {
            $node->setAttribute('default', '#000000');
        }

        return $node;
    }
}
```

### 4. `tmpl/color.php`

Θυμίζουμε: **υποχρεωτικά να υπάρχει** (marker για `isTypeSupported('color')`). Το περιεχόμενο τρέχει μόνο όταν ενεργοποιηθεί το display pipeline (§§7-8).

```php
<?php
defined('_JEXEC') or die;

/** @var object $field */
$value = $field->value ?? '';
if ($value === '' || $value === null) {
    return;
}

$safe       = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$showSwatch = (int) ($field->fieldparams->get('show_swatch', 1));

if ($showSwatch) {
    echo '<span class="alfa-color-swatch" '
       . 'style="display:inline-block;width:1em;height:1em;border:1px solid #ccc;'
       . 'background:' . $safe . ';vertical-align:middle;margin-right:.3em;"></span>';
}

echo '<code>' . $safe . '</code>';
```

### 5. `language/en-GB/plg_alfa-fields_color.sys.ini`

```ini
PLG_ALFA_FIELDS_COLOR="Alfa Fields - Color"
PLG_ALFA_FIELDS_COLOR_XML_DESCRIPTION="Color picker custom field for Alfa Commerce."
```

### 6. `language/en-GB/plg_alfa-fields_color.ini`

```ini
PLG_ALFA_FIELDS_COLOR_FORMAT_LABEL="Storage format"
PLG_ALFA_FIELDS_COLOR_SWATCH_LABEL="Show color swatch on output"
```

### Εγκατάσταση & δοκιμή

1. **Install** το plugin (zip τον φάκελο `plugins/alfa-fields/color/` και install μέσω Extensions → Install, ή manual entry στον `#__extensions` αν το κάνεις dev).
2. **Enable** το plugin (`System → Manage → Plugins`).
3. Πήγαινε σε `com_alfa` → Custom Fields → New, επίλεξε τύπο `color`, βάλε context (π.χ. `com_alfa.product`), save.
4. Άνοιξε ένα product στη διαχείριση → βλέπεις **native color picker** στη φόρμα. Αυτός είναι ο `prepareDom` στη δουλειά.
5. Η τιμή αποθηκεύεται ως `#rrggbb`.

**Τι ΔΕΝ θα δεις ακόμα:** το swatch + hex στο frontend. Αυτό χρειάζεται το display pipeline (§§7-8) + κλήση του `FieldsHelper::renderFieldValue()` μέσα στο product view tmpl.

### Τι έμαθες από αυτό το παράδειγμα

| Κομμάτι | Ρόλος |
|---|---|
| `color.xml` | Joomla install manifest + plugin params (format, show_swatch) |
| `services/provider.php` | DI registration — συνδέει το class με τον dispatcher |
| `src/Extension/Color.php::prepareDom()` | **Φάση A** — κάνει το field να εμφανιστεί στη φόρμα ως color input |
| `tmpl/color.php` (ύπαρξη) | **Marker** — δηλώνει ότι ο τύπος `color` υποστηρίζεται |
| `tmpl/color.php` (περιεχόμενο) | **Φάση B** — swatch + hex για output (ενεργό μόνο μετά από §§7-8) |

**Παρατήρηση:** αν σβήσεις το `tmpl/color.php`, **η φόρμα σταματάει να δείχνει το color picker** (ο `isTypeSupported('color')` γυρνάει false και το `prepareDom` δεν τρέχει). Αυτό είναι το βασικό σημείο που συχνά μπερδεύει — το αρχείο **πρέπει να υπάρχει** ακόμα και πριν ενεργοποιηθεί το display pipeline.

---

## 10. Custom field στο cart / checkout

**Σενάριο A — Απλό input (ΑΦΜ, σχόλιο, ώρα παράδοσης):**
- Δημιούργησε field στη διαχείριση με σωστό `context` (π.χ. `com_alfa.order`)
- Στο cart/checkout template καλείς `FieldsHelper::prepareForm($context, $form, $data)` και μετά `$form->renderFieldset('...')`
- **Αρκεί ο `prepareDom`** — δουλεύει ήδη

**Σενάριο B — Εμφάνιση αποθηκευμένης τιμής σε invoice / order detail / email:**
- Σενάριο A + ενεργοποίηση display pipeline (§§7-8)
- Μετά καλείς `FieldsHelper::renderFieldValue($field, $item)` όπου θες

---

## 11. Gotchas

1. **Cache** — ο `getSupportedFieldTypes()` κρατάει static cache (`FieldsPlugin.php:405`). Νέο `tmpl/*.php` σε υπάρχον plugin → reset OPcache ή restart PHP-FPM.

2. **Plugin install** — νέο `alfa-fields/<name>` plugin δεν φτάνει να πέσει στο filesystem. Πρέπει install (ή manual entry στον `#__extensions`).

3. **`services/provider.php`** — απαραίτητο σε κάθε νέο plugin. Copy-paste από `tel` ή `text` και άλλαξε μόνο class namespace/name.

4. **Context matching** — το `$context` στο `FieldsHelper::prepareForm()` πρέπει να ταιριάζει ακριβώς με το `context` στα `#__alfa_fields`. `com_alfa.order` ≠ `com_alfa.orders`.

5. **ACL** — ο `FieldsPlugin::canEditFieldValue()` επιστρέφει `FieldsHelper::canEditFieldValue()`. Αν ο user δεν έχει δικαίωμα, το node γίνεται `disabled` + `readonly`. Στο checkout για guest, βεβαιώσου ότι περνάει.

6. **Validation class naming** — lowercase. `alfatel` σωστό, `alfaTel` **σπάει** το `FormHelper::loadClass` (splits σε sub-namespace). Βλ. σχόλιο στο `Tel.php:24`.

---

## 12. Cheat sheet

```
prepareDom  = input side  → φόρμα εισαγωγής      → ενεργό
tmpl/*.php  = output side → εμφάνιση τιμής       → νεκρό (θέλει §§7-8)

Διπλός ρόλος του tmpl:
  1. Marker για isTypeSupported()  → ΜΗΝ ΤΟ ΣΒΗΣΕΙΣ
  2. Display template              → ενεργό μόνο μετά από §§7-8

Σχολιασμένος κώδικας:
  → core Joomla blueprint, όχι σκουπίδια
  → ΜΗΝ uncomment — άλλαξε event names σε 'onAlfaFields*'

Έτοιμα Event skeletons:
  → _PrepareFieldEvent.php + _AbstractPrepareFieldEvent.php
  → rename (αφαίρεση underscore) + διόρθωσε namespace

Νέος τύπος:
  → Επίπεδο 1: wrapper built-in Joomla (5λ)
  → Επίπεδο 2: δική σου FormField class (1-2 ώρες)
  → Επίπεδο 3: type=note (info only)

Cart field:
  → Μόνο input     → prepareDom (ήδη OK)
  → Input + display → prepareDom + §§7-8
```
