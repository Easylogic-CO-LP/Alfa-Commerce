/**
 * SEO Preview with AJAX - Simplified Version
 *
 * @package Com_Alfa
 * @subpackage Administrator
 * @version 4.0.0
 */

(function(document, Joomla) {
    'use strict';

    /**
     * SEO Preview AJAX Handler
     */
    class SEOPreview {
        constructor() {
            // Get configuration from Joomla script options
            const options = Joomla.getOptions('seo-preview');

            if (!options) {
                console.warn('SEO Preview: No configuration found');
                return;
            }

            // Configuration from PHP
            this.itemType = options.itemType || 'category';
            this.itemId = options.itemId || 0;
            this.defaultAlias = options.defaultAlias || '';
            this.debounceDelay = options.debounceDelay || 500;
            this.endpoint = options.endpoint || 'index.php?option=com_alfa&task=seo.getPreview&format=json';
            this.debug = options.debug || false;

            console.log(options);
            // Get field selectors from config and find elements
            const fieldSelectors = options.fields || {};
            this.fields = {
                title: fieldSelectors.title ? document.querySelector(fieldSelectors.title) : null,
                metaTitle: fieldSelectors.metaTitle ? document.querySelector(fieldSelectors.metaTitle) : null,
                metaDesc: fieldSelectors.metaDesc ? document.querySelector(fieldSelectors.metaDesc) : null,
                alias: fieldSelectors.alias ? document.querySelector(fieldSelectors.alias) : null,
                content: fieldSelectors.content ? document.querySelector(fieldSelectors.content) : null,
                focusKeyword: fieldSelectors.focusKeyword ? document.querySelector(fieldSelectors.focusKeyword) : null,
                robots: fieldSelectors.robots ? document.querySelector(fieldSelectors.robots) : null
            };

            // Handle additional content fields
            this.additionalContentFields = {};
            if (fieldSelectors.additionalContent && typeof fieldSelectors.additionalContent === 'object') {
                Object.keys(fieldSelectors.additionalContent).forEach(key => {
                    const selector = fieldSelectors.additionalContent[key];
                    const element = document.querySelector(selector);
                    if (element) {
                        this.additionalContentFields[key] = element;
                    } else {
                        console.warn(`SEO Preview: Additional content field not found: ${key} (${selector})`);
                    }
                });
            }

            // Warn about missing fields (helpful for debugging)
            this.validateFields(fieldSelectors);

            // Store field IDs for editor access
            this.fieldIds = {
                content: fieldSelectors.content ? fieldSelectors.content.replace('#', '') : null
            };

            // Store additional content field IDs for editor access
            this.additionalContentFieldIds = {};
            if (fieldSelectors.additionalContent) {
                Object.keys(fieldSelectors.additionalContent).forEach(key => {
                    const selector = fieldSelectors.additionalContent[key];
                    this.additionalContentFieldIds[key] = selector.replace('#', '');
                });
            }

            this.container = document.querySelector('[data-seo-preview-container]');
            this.debounceTimer = null;

            // Initialize if container exists
            if (this.container) {
                this.init();
            } else {
                console.warn('SEO Preview: Container not found [data-seo-preview-container]');
            }
        }

        /**
         * Validate that fields were found
         */
        validateFields(fieldSelectors) {
            const missingFields = [];

            Object.keys(fieldSelectors).forEach(key => {
                if (key === 'additionalContent') return; // Skip, handled separately

                if (fieldSelectors[key] && !this.fields[key]) {
                    missingFields.push(`${key} (${fieldSelectors[key]})`);
                }
            });

            if (missingFields.length > 0) {
                console.warn('SEO Preview: The following fields were not found:', missingFields.join(', '));
            }
        }

        /**
         * Initialize event listeners
         */
        init() {
            // Attach listeners to main text fields
            Object.keys(this.fields).forEach(key => {
                const field = this.fields[key];

                if (!field || field === this.fields.content) {
                    return;
                }

                field.addEventListener('input', () => this.handleFieldChange());
                field.addEventListener('blur', () => this.handleBlur());
            });

            // Attach listeners to additional content fields
            Object.keys(this.additionalContentFields).forEach(key => {
                const field = this.additionalContentFields[key];
                field.addEventListener('input', () => this.handleFieldChange());
                field.addEventListener('blur', () => this.handleBlur());
            });

            // Special handling for title changes
            if (this.fields.title) {
                this.fields.title.addEventListener('blur', () => this.handleTitleBlur());
            }
            if (this.fields.metaTitle) {
                this.fields.metaTitle.addEventListener('blur', () => this.handleTitleBlur());
            }

            // Handle content field (may be an editor)
            this.initContentField();

            // Handle additional content fields (may be editors)
            this.initAdditionalContentFields();

            // Handle refresh button
            document.addEventListener('click', (e) => {
                if (e.target.closest('[data-seo-refresh-btn]')) {
                    e.preventDefault();
                    this.updatePreview();
                }
            });
        }

        /**
         * Initialize content field tracking
         */
        initContentField() {
            if (!this.fields.content || !this.fieldIds.content) return;

            const fieldId = this.fieldIds.content;

            const checkEditor = () => {
                if (window.tinyMCE && window.tinyMCE.get(fieldId)) {
                    if (this.debug) console.log('SEO Preview: TinyMCE editor found for content');
                    this.attachEditorListener(fieldId);
                    return;
                }

                if (window.JoomlaEditor && window.JoomlaEditor.get(fieldId)) {
                    if (this.debug) console.log('SEO Preview: JoomlaEditor instance found for content');
                    this.attachEditorListener(fieldId);
                    return;
                }

                setTimeout(checkEditor, 500);
            };

            checkEditor();
        }

        /**
         * Initialize additional content fields tracking
         */
        initAdditionalContentFields() {
            Object.keys(this.additionalContentFieldIds).forEach(key => {
                const fieldId = this.additionalContentFieldIds[key];

                const checkEditor = () => {
                    if (window.tinyMCE && window.tinyMCE.get(fieldId)) {
                        if (this.debug) console.log(`SEO Preview: TinyMCE editor found for ${key}`);
                        this.attachEditorListener(fieldId);
                        return;
                    }

                    if (window.JoomlaEditor && window.JoomlaEditor.get(fieldId)) {
                        if (this.debug) console.log(`SEO Preview: JoomlaEditor instance found for ${key}`);
                        this.attachEditorListener(fieldId);
                        return;
                    }

                    setTimeout(checkEditor, 500);
                };

                checkEditor();
            });
        }

        /**
         * Attach listener to editor
         */
        attachEditorListener(fieldId) {
            if (window.tinyMCE) {
                const editor = window.tinyMCE.get(fieldId);
                if (editor) {
                    if (this.debug) console.log(`SEO Preview: Attaching TinyMCE events to ${fieldId}`);
                    editor.on('change', () => this.handleFieldChange());
                    editor.on('keyup', () => this.handleFieldChange());
                    editor.on('blur', () => this.handleBlur());
                    return;
                }
            }

            if (window.JoomlaEditor) {
                const editorInstance = window.JoomlaEditor.get(fieldId);
                if (editorInstance && editorInstance.codemirror) {
                    if (this.debug) console.log(`SEO Preview: Attaching CodeMirror events to ${fieldId}`);
                    const cm = editorInstance.codemirror;
                    cm.on('change', () => this.handleFieldChange());
                    cm.on('blur', () => this.handleBlur());
                    return;
                }
            }

            if (this.debug) console.log(`SEO Preview: Using textarea fallback for ${fieldId}`);
            const textarea = document.getElementById(fieldId);
            if (textarea) {
                textarea.addEventListener('input', () => this.handleFieldChange());
                textarea.addEventListener('blur', () => this.handleBlur());
            }
        }

        /**
         * Handle title blur - generate alias if needed
         */
        handleTitleBlur() {
            clearTimeout(this.debounceTimer);
            this.updatePreview();
        }

        /**
         * Handle blur on any field - update immediately
         */
        handleBlur() {
            clearTimeout(this.debounceTimer);
            this.updatePreview();
        }

        /**
         * Handle field change with debouncing (while typing)
         */
        handleFieldChange() {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.updatePreview();
            }, this.debounceDelay);
        }

        /**
         * Get content from editor or field
         */
        getContentFromField(fieldId) {
            if (!fieldId) return '';

            // Try TinyMCE
            if (window.tinyMCE) {
                const editor = window.tinyMCE.get(fieldId);
                if (editor) {
                    return editor.getContent();
                }
            }

            // Try JoomlaEditor API
            if (window.JoomlaEditor) {
                const editor = window.JoomlaEditor.get(fieldId);
                if (editor && typeof editor.getValue === 'function') {
                    return editor.getValue();
                }
            }

            // Fallback to textarea/input
            const element = document.getElementById(fieldId);
            return element ? element.value : '';
        }

        /**
         * Get content from main editor
         */
        getEditorContent() {
            if (!this.fieldIds.content) return '';
            return this.getContentFromField(this.fieldIds.content);
        }

        /**
         * Get additional content fields data
         */
        getAdditionalContent() {
            const data = {};

            Object.keys(this.additionalContentFieldIds).forEach(key => {
                const fieldId = this.additionalContentFieldIds[key];
                data[key] = this.getContentFromField(fieldId);
            });

            return data;
        }

        /**
         * Get form data
         */
        getFormData() {
            const data = {
                title: this.fields.title ? this.fields.title.value : '',
                metaTitle: this.fields.metaTitle ? this.fields.metaTitle.value : '',
                metaDesc: this.fields.metaDesc ? this.fields.metaDesc.value : '',
                defaultAlias: this.defaultAlias,
                alias: this.fields.alias ? this.fields.alias.value : '',
                focusKeyword: this.fields.focusKeyword ? this.fields.focusKeyword.value : '',
                robots: this.fields.robots ? this.fields.robots.value : '',
                content: this.getEditorContent(),
                itemId: this.itemId,
                itemType: this.itemType
            };

            // Add additional content fields
            const additionalContent = this.getAdditionalContent();
            if (Object.keys(additionalContent).length > 0) {
                // Send as nested object - PHP will receive it correctly
                Object.keys(additionalContent).forEach(key => {
                    data[`additionalContent[${key}]`] = additionalContent[key];
                });
            }

            return data;
        }

        /**
         * Update SEO preview via AJAX
         */
        async updatePreview() {
            try {
                const formData = this.getFormData();
                const basePath = Joomla.getOptions('system.paths').base || '';
                const url = basePath + '/' + this.endpoint;

                const params = new URLSearchParams(formData);

                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: params
                });

                if (!response.ok) {
                    throw new Error('Network response error');
                }

                const responseData = await response.json();

                if (responseData.success) {
                    this.updatePreviewContent(responseData.data.html);
                } else {
                    console.error('SEO Preview Error:', responseData.message);
                }
            } catch (error) {
                console.error('SEO Preview AJAX Error:', error);
            }
        }

        /**
         * Update preview content in DOM
         */
        updatePreviewContent(html) {
            const container = document.querySelector('[data-seo-preview-container]');
            if (!container) {
                console.warn('SEO Preview: Container not found during update');
                return;
            }

            const previewLayout = document.createElement('div');
            previewLayout.innerHTML = html;

            const headerContainer = previewLayout.querySelector('[data-seo-preview-header]');
            const existingHeader = container.querySelector('[data-seo-preview-header]');

            if (headerContainer && existingHeader) {
                existingHeader.innerHTML = headerContainer.innerHTML;
            }

            const resultContainer = previewLayout.querySelector('[data-seo-preview-result]');
            const existingResult = container.querySelector('[data-seo-preview-result]');

            if (resultContainer && existingResult) {
                existingResult.innerHTML = resultContainer.innerHTML;
            }
        }
    }

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        if (document.querySelector('[data-seo-preview-container]')) {
            window.alfaSeoPreview = new SEOPreview();
        }
    });

})(document, window.Joomla || {});