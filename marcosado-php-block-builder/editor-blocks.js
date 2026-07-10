(function (blocks, element, serverSideRender, components, blockEditor) {
    var el               = element.createElement;
    var ServerSideRender = serverSideRender;
    var InspectorControls = blockEditor ? blockEditor.InspectorControls : window.wp.editor.InspectorControls;
    var MediaUpload       = blockEditor ? blockEditor.MediaUpload : window.wp.editor.MediaUpload;
    var PanelBody        = components ? components.PanelBody : null;

    var CONTROL_MAP = {
        text:    components ? components.TextControl : null,
        textarea: components ? components.TextareaControl : null,
        number:  components ? components.RangeControl : null,
        boolean: components ? components.ToggleControl : null,
        color:   components ? components.ColorPicker : null,
        select:  components ? components.SelectControl : null,
        image:   null // Géré manuellement ci-dessous
    };

    // ── Helper : parse "val1:Label1,val2:Label2" → [{value, label}] pour SelectControl
    function parseSelectOptions(raw) {
        if (!raw) return [{value: '', label: '— choisir —'}];
        var opts = [];
        raw.split(',').forEach(function(pair) {
            pair = pair.trim();
            if (!pair) return;
            var parts = pair.split(':');
            var val   = parts[0].trim();
            var label = parts.length > 1 ? parts.slice(1).join(':').trim() : val;
            opts.push({value: val, label: label});
        });
        return opts.length ? opts : [{value: '', label: '— choisir —'}];
    }

    // ── Blocs utilisateur (créés via le Lab) ─────────────────────────────────
    if (typeof SMBlocksConfig !== 'undefined') {
        Object.keys(SMBlocksConfig).forEach(function (key) {
            var block = SMBlocksConfig[key];
            
            // Build Gutenberg attributes array from config
            var attrs = {};
            if (block.attributes && block.attributes.length > 0) {
                block.attributes.forEach(function(field) {
                    var defaultVal;
                    if (field.field_type === 'number') {
                        defaultVal = parseInt(field.field_default, 10) || 0;
                    } else if (field.field_type === 'boolean') {
                        defaultVal = (field.field_default === 'true' || field.field_default === '1');
                    } else if (field.field_type === 'select') {
                        // Valeur par défaut = 1er option de la liste
                        var firstOpt = parseSelectOptions(field.field_default)[0];
                        defaultVal = firstOpt ? firstOpt.value : '';
                    } else {
                        defaultVal = field.field_default;
                    }
                    attrs[field.field_key] = {
                        type:    field.field_type === 'number' ? 'number' : (field.field_type === 'boolean' ? 'boolean' : 'string'),
                        default: defaultVal,
                    };
                });
            }

            blocks.registerBlockType(block.name, {
                title:    block.title,
                icon:     'layout',
                category: 'design',
                attributes: attrs,
                edit: function (props) {
                    var elements = [];

                    // Generate Inspector Controls if the block has attributes
                    if (block.attributes && block.attributes.length > 0 && InspectorControls && PanelBody) {
                        var sections = {};
                        block.attributes.forEach(function(field) {
                            var sectionName = field.field_section || 'Général';
                            if (!sections[sectionName]) {
                                sections[sectionName] = [];
                            }

                            var Control = CONTROL_MAP[field.field_type] || components.TextControl;
                            var controlArgs = {
                                label: field.field_label || field.field_key,
                                value: props.attributes[field.field_key],
                                onChange: function(val) {
                                    var update = {};
                                    update[field.field_key] = val;
                                    props.setAttributes(update);
                                }
                            };
                            
                            // Specific adjustments per type
                            if (field.field_type === 'boolean') {
                                controlArgs.checked = props.attributes[field.field_key];
                                delete controlArgs.value;
                            }
                            if (field.field_type === 'number') {
                                controlArgs.min = 1;
                                controlArgs.max = 100;
                            }
                            // SELECT : fournir les options parsées
                            if (field.field_type === 'select') {
                                controlArgs.options = parseSelectOptions(field.field_default);
                            }
                            
                            var finalElement;
                            if (field.field_type === 'color') {
                                controlArgs = {
                                    color:    props.attributes[field.field_key],
                                    onChange: function(val) {
                                        var update = {};
                                        update[field.field_key] = val.hex !== undefined ? val.hex : val;
                                        props.setAttributes(update);
                                    }
                                };
                                finalElement = el('div', { style: { marginBottom: '15px' }, key: field.field_key },
                                    el('p', { style: { marginBottom: '8px', fontSize: '13px' } }, field.field_label || field.field_key),
                                    el(Control, controlArgs)
                                );
                            } else if (field.field_type === 'image' && MediaUpload) {
                                finalElement = el('div', { style: { marginBottom: '15px' }, key: field.field_key }, 
                                    el('p', { style: { marginBottom: '8px', fontSize: '13px' } }, field.field_label || field.field_key),
                                    el(MediaUpload, {
                                        onSelect: function(media) {
                                            var update = {};
                                            // Enregistrer directement l'URL de l'image
                                            update[field.field_key] = media.url;
                                            props.setAttributes(update);
                                        },
                                        allowedTypes: ['image'],
                                        value: props.attributes[field.field_key],
                                        render: function(obj) {
                                            return el('div', {},
                                                props.attributes[field.field_key] ? el('img', { src: props.attributes[field.field_key], style: { maxWidth: '100%', height: 'auto', marginBottom: '10px', borderRadius: '4px', border: '1px solid #ccc' } }) : null,
                                                el('div', {}, 
                                                    el(components.Button, {
                                                        onClick: obj.open,
                                                        variant: 'secondary'
                                                    }, props.attributes[field.field_key] ? 'Changer l\'image' : 'Choisir une image')
                                                )
                                            );
                                        }
                                    })
                                );
                            } else {
                                controlArgs.key = field.field_key;
                                finalElement = el(Control, controlArgs);
                            }

                            sections[sectionName].push(finalElement);
                        });

                        var panelBodies = Object.keys(sections).map(function(secName) {
                            return el(PanelBody, { title: secName, initialOpen: false, key: 'panel_' + secName },
                                sections[secName]
                            );
                        });

                        elements.push(
                            el(InspectorControls, { key: 'inspector' },
                                panelBodies
                            )
                        );
                    }

                    // Push the ServerSideRender for the actual preview
                    elements.push(
                        el(ServerSideRender, {
                            key: 'preview',
                            block: block.name,
                            attributes: props.attributes,
                        })
                    );

                    return elements;
                },
                save: function () { return null; },
            });
        });
    }

})(
    window.wp.blocks,
    window.wp.element,
    window.wp.serverSideRender,
    window.wp.components,
    window.wp.blockEditor || window.wp.editor
);
