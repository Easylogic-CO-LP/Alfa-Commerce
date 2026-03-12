/**
 * @package     Alfa.Administrator
 * @subpackage  com_alfa
 * @version     1.0.2
 *
 * Media Manager — handles drag-and-drop uploads, media picker integration,
 * URL insertion, sortable grid, thumbnails, and delete actions.
 */

(function () {
    'use strict';

    window.MediaManagerDebug = true;

    const MediaManager = {

        config: {
            // ── Containers ──
            gridSelector:               '#media-grid',
            dropzoneSelector:           '.media-dropzone-inner',
            dropzoneOverlaySelector:    '.media-dropzone-overlay',
            formSelector:               '.adminForm',

            // ── Media card ──
            cardSelector:               '.media-card',
            deleteButtonSelector:       '.media-btn-delete',
            deleteFlagSelector:         '.media-delete-flag',
            sourceInputSelector:        '.media-source-input',

            // ── Thumbnail ──
            thumbnailInputSelector:     '.media-thumbnail-input',
            thumbnailImageSelector:     '.media-thumbnail-img',
            thumbnailButtonSelector:    '.media-thumbnail-btn',
            thumbnailPickerInputSelector: '.media-thumbnail-picker-input',
            thumbnailOpenBtnSelector:   '#media-thumbnail-open-btn',

            // ── Media picker (library) ──
            pickerInputSelector:        '#media-picker-input',
            pickerOpenBtnSelector:      '#media-picker-open-btn',

            // ── File input (drag & drop) ──
            fileInputSelector:          '#media-file-input',

            // ── Toolbar ──
            mediaOptionsDropdownButton: '.button-media-actions',
            selectLibraryBtnSelector:   '.media-action-select-media',

            // ── URL modal ──
            urlModalSelector:           '#selectUrlModal',
            urlInputSelector:           '#media-url-input',
            urlErrorSelector:           '#media-url-error',
            urlSubmitSelector:          '#media-url-submit',
            urlThumbnailBtnSelector:    '.media-url-thumbnail-btn',

            // ── Placeholder ──
            placeholderSelector:        '.media-placeholder',

            // ── AJAX ──
            ajaxEndpoint: 'index.php?option=com_alfa&task=media.getSource&format=json',

            // ── Validation ──
            maxFileSize: 5 * 1024 * 1024,
            allowedMimes: Joomla.getOptions('com_alfa.mimes'),

            // ── Allow Multiple Media ──
            allowMultiple: Joomla.getOptions('com_alfa.multiple'),

            // ── Allowed Types ──
            allowedTypes: Joomla.getOptions('com_alfa.types'),

            // ── Sortable ──
            sortableOptions: {
                draggable:  '.media-card',
                handle:     '.drag-handle',
                animation:  150,
                ghostClass:  'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass:   'sortable-drag',
            },

            // ── Messages ──
            messages: {
                deleteConfirm:    'COM_ALFA_MEDIA_DELETE_CONFIRM',
                noValidFiles:     'COM_ALFA_MEDIA_NO_VALID_FILES',
                someInvalid:      'COM_ALFA_MEDIA_SOME_INVALID',
                loadPreviewError: 'COM_ALFA_MEDIA_LOAD_PREVIEW_ERROR',
                placeholderText:  'COM_ALFA_MEDIA_NO_ITEMS',
            },
        },

        fileMap: new Map(),
        sortableInstance: null,
        basePath: '',

        // =====================================================================
        //  INIT
        // =====================================================================

        init() {
            if (window.MediaManagerConfig) {
                this.config = this.deepMerge(this.config, window.MediaManagerConfig);
            }

            this.basePath = Joomla.getOptions('system.paths').base || '';

            const allowedTypes = this.config.allowedTypes;

            if (allowedTypes.includes('media')) {
                this.setupDropArea();
            } else {
                const dropzone = document.querySelector(this.config.dropzoneSelector);
                if (dropzone) {
                    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
                        dropzone.addEventListener(evt, (e) => {
                            e.preventDefault();
                            e.stopPropagation();

                            if (evt === 'drop') {
                                this.showError('Media type is not allowed!');
                            }
                        }, false);
                    });
                }
            }

            this.setupMediaPicker();
            this.setupSortable();
            this.setupDeleteHandlers();
            this.setupUrlModal();
            this.setupThumbnailPicker();

            this.updateFileInputOrder();

            if (window.MediaManagerDebug) {
                console.log('[MediaManager] Initialized');
            }
        },

        // =====================================================================
        //  UTILITIES
        // =====================================================================

        deepMerge(target, source) {
            const output = Object.assign({}, target);
            if (this.isObject(target) && this.isObject(source)) {
                Object.keys(source).forEach(key => {
                    if (this.isObject(source[key])) {
                        if (!(key in target)) {
                            Object.assign(output, { [key]: source[key] });
                        } else {
                            output[key] = this.deepMerge(target[key], source[key]);
                        }
                    } else {
                        Object.assign(output, { [key]: source[key] });
                    }
                });
            }
            return output;
        },

        isObject(item) {
            return item && typeof item === 'object' && !Array.isArray(item);
        },

        getText(key, defaultText) {
            if (typeof Joomla !== 'undefined' && Joomla.JText) {
                return Joomla.JText._(key, defaultText);
            }
            return defaultText;
        },

        showError(message) {
            if (typeof Joomla !== 'undefined' && Joomla.renderMessages) {
                Joomla.renderMessages({ error: [message] });
            } else {
                alert(message);
            }
        },

        showWarning(message) {
            if (typeof Joomla !== 'undefined' && Joomla.renderMessages) {
                Joomla.renderMessages({ warning: [message] });
            } else {
                console.warn(message);
            }
        },

        // =====================================================================
        //  DROP AREA
        // =====================================================================

        setupDropArea() {
            const dropzone = document.querySelector(this.config.dropzoneSelector);
            const overlay  = document.querySelector(this.config.dropzoneOverlaySelector);

            if (!dropzone || !overlay) return;

            const preventDefaults = (e) => {
                e.preventDefault();
                e.stopPropagation();
            };

            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
                dropzone.addEventListener(evt, preventDefaults, false);
            });

            const handleDragEnter = (e) => {
                const types = e.dataTransfer?.types ? Array.from(e.dataTransfer.types) : [];
                if (types.includes('Files')) overlay.classList.add('hovered');
            };

            const handleDragLeave = (e) => {
                if (e.target === dropzone || !dropzone.contains(e.relatedTarget)) {
                    overlay.classList.remove('hovered');
                }
            };

            ['dragenter', 'dragover'].forEach(evt => dropzone.addEventListener(evt, handleDragEnter));
            ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, handleDragLeave));

            dropzone.addEventListener('drop', (e) => {
                overlay.classList.remove('hovered');

                this.handleDrop(e);
            });
        },

        handleDrop(e) {
            const files = Array.from(e.dataTransfer.files);
            if (files.length === 0) return;

            const availableSlots = this.checkMediaSlotAvailability();
            if (availableSlots === 0) return;

            let validFiles = files.filter(file => this.validateFile(file));

            if (validFiles.length === 0) {
                this.showError(this.getText(this.config.messages.noValidFiles, 'No valid image files found'));
                return;
            }

            if (validFiles.length > availableSlots) {
                validFiles = validFiles.slice(0, availableSlots);
                this.showWarning(`Limit reached. Only ${availableSlots} file(s) were added.`);
            } else if (validFiles.length < files.length) {
                this.showWarning(this.getText(this.config.messages.someInvalid, 'Some files were skipped'));
            }

            validFiles.forEach(file => this.addDroppedFile(file));
        },

        validateFile(file) {
            if (this.config.allowedMimes === null || !this.config.allowedMimes.includes(file.type)) {
                alert(`MIME ${file.type} is not supported!`);
                return false;
            }
            return file.size <= this.config.maxFileSize;
        },

        addDroppedFile(file) {
            const blobUrl = URL.createObjectURL(file);
            this.fileMap.set(blobUrl, file);
            this.fetchMediaPreview(blobUrl, blobUrl, 'drop');
        },

        // =====================================================================
        //  MEDIA PICKER (library)
        // =====================================================================

        setupMediaPicker() {
            const toolbarBtn = document.querySelector(this.config.selectLibraryBtnSelector);
            const hiddenBtn  = document.querySelector(this.config.pickerOpenBtnSelector);
            const pickerInput = document.querySelector(this.config.pickerInputSelector);

            if (!toolbarBtn || !hiddenBtn || !pickerInput) return;

            toolbarBtn.addEventListener('click', () => {
                const availableSlots = this.checkMediaSlotAvailability();

                if (availableSlots === 0) {
                    toolbarBtn.disabled = true;
                    return;
                }

                hiddenBtn.click();
                pickerInput.addEventListener('change', () => {
                    const selectedPath = pickerInput.value;
                    if (selectedPath) {
                        this.addMediaFromPicker(selectedPath);
                        pickerInput.value = '';
                    }
                });
            });
        },

        addMediaFromPicker(path) {
            this.fetchMediaPreview(path, path, 'picker');
        },

        // =====================================================================
        //  SORTABLE
        // =====================================================================

        setupSortable() {
            const container = document.querySelector(this.config.gridSelector);
            if (!container) return;

            this.updatePlaceholder();

            this.sortableInstance = new Sortable(container, {
                ...this.config.sortableOptions,
                onEnd: (evt) => {
                    this.updateFileInputOrder();

                    if (window.MediaManagerDebug) {
                        console.log('[MediaManager] Reordered:', evt.oldIndex, '→', evt.newIndex);
                    }
                },
            });
        },

        // =====================================================================
        //  DELETE
        // =====================================================================

        setupDeleteHandlers() {
            const container = document.querySelector(this.config.gridSelector);
            if (!container) return;

            container.addEventListener('click', (e) => {
                if (!e.target.closest(this.config.deleteButtonSelector)) return;

                const card = e.target.closest(this.config.cardSelector);
                if (!card) return;

                const isNew   = card.dataset.isNew === '1';
                const message = this.getText(this.config.messages.deleteConfirm, 'Delete this image?');

                if (!confirm(message)) return;

                if (isNew) {
                    const sourceInput = card.querySelector(this.config.sourceInputSelector);
                    if (sourceInput && sourceInput.value.startsWith('blob:')) {
                        this.fileMap.delete(sourceInput.value);
                    }
                    card.remove();
                    this.updatePlaceholder();
                    this.updateFileInputOrder();
                } else {
                    const deleteFlag = card.querySelector(this.config.deleteFlagSelector);
                    if (deleteFlag) {
                        deleteFlag.checked = true;
                        card.style.display = 'none';
                    }
                }
            });
        },

        // =====================================================================
        //  THUMBNAIL PICKER
        // =====================================================================

        setupThumbnailPicker() {
            const sharedInput = document.querySelector(this.config.thumbnailPickerInputSelector);
            const hiddenBtn   = document.querySelector(this.config.thumbnailOpenBtnSelector);
            const container   = document.querySelector(this.config.gridSelector);

            if (!sharedInput || !hiddenBtn || !container) return;

            let selectedCard = null;

            container.addEventListener('click', (e) => {
                const btn = e.target.closest(this.config.thumbnailButtonSelector);
                if (!btn) return;

                e.preventDefault();
                selectedCard = btn.closest(this.config.cardSelector);
                hiddenBtn.click();
            });

            sharedInput.addEventListener('change', () => {
                if (!selectedCard) return;

                const newValue = sharedInput.value;

                const safeUrlValue = encodeURI(newValue);
                const img   = selectedCard.querySelector(this.config.thumbnailImageSelector);
                const input = selectedCard.querySelector(this.config.thumbnailInputSelector);

                if (img)   img.src     = '/' + safeUrlValue;

                if (input) input.value = newValue;

                sharedInput.value = '';
                selectedCard = null;
            });
        },

        // =====================================================================
        //  URL MODAL
        // =====================================================================

        setupUrlModal() {
            const modalEl      = document.querySelector(this.config.urlModalSelector);
            const urlInput     = document.querySelector(this.config.urlInputSelector);
            const urlError     = document.querySelector(this.config.urlErrorSelector);
            const urlSubmit    = document.querySelector(this.config.urlSubmitSelector);
            const thumbBtn     = document.querySelector(this.config.urlThumbnailBtnSelector);
            const pickerInput  = document.querySelector(this.config.pickerInputSelector);
            const pickerOpenBtn = document.querySelector(this.config.pickerOpenBtnSelector);

            if (!modalEl || !urlSubmit) return;

            const modal = bootstrap.Modal.getInstance(modalEl);

            // Thumbnail preview inside URL modal
            const thumbPreview = document.createElement('img');
            thumbPreview.style.cssText = 'margin-top:10px;margin-left:3px;border-radius:15px;object-fit:cover;';

            if (thumbBtn && pickerInput && pickerOpenBtn) {
                thumbBtn.addEventListener('click', () => pickerOpenBtn.click());

                pickerInput.addEventListener('change', () => {
                    thumbPreview.width  = 100;
                    thumbPreview.height = 100;
                    thumbPreview.src    = '/' + pickerInput.value;
                    thumbBtn.insertAdjacentElement('afterend', thumbPreview);
                });
            }

            urlSubmit.addEventListener('click', (e) => {
                e.preventDefault();

                const availableSlots = this.checkMediaSlotAvailability();

                if (availableSlots === 0) {
                    urlSubmit.disabled = true;
                    return;
                }

                const formData = new FormData();
                formData.append('type', 'url');
                formData.append('url', urlInput.value);
                formData.append('thumbnail', pickerInput ? pickerInput.value : '');

                fetch(this.config.ajaxEndpoint, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.addPreviewToContainer(data.html);
                            urlError.classList.remove('visible');
                            modal.hide();
                            urlInput.value = '';
                        } else {
                            urlError.innerText = data.message;
                            urlError.classList.add('visible');
                            console.error('Error:', data.message);
                        }
                    })
                    .catch(error => console.error('Fetch error:', error));
            });
        },

        // =====================================================================
        //  MEDIA UPLOAD LIMITER
        // =====================================================================

        mediaLimiter(limit) {
            const inputs = document.querySelectorAll('input[name^="jform[media]"][name$="[type]"]');

            const uniqueMediaIds = new Set();

            inputs.forEach(input => {
                const match = input.name.match(/jform\[media\]\[([^\]]+)\]\[type\]/);
                if (match) {
                    uniqueMediaIds.add(match[1]);
                }
            });

            return uniqueMediaIds.size;
        },

        checkMediaSlotAvailability() {
            const currentTotal= this.mediaLimiter();
            const maxAllowed= this.config.allowMultiple ? 100 : 1;
            const availableSlots= maxAllowed - currentTotal;

            if (availableSlots <= 0) {
                this.showError('Maximum media limit reached. Cannot add more files.');
                return 0;
            }

            return availableSlots;
        },

        // =====================================================================
        //  PREVIEW / GRID HELPERS
        // =====================================================================

        fetchMediaPreview(mediaData, identifier, type) {
            const formData = new FormData();
            formData.append('mediaData', mediaData);
            formData.append('identifier', identifier);
            formData.append('type', type);

            fetch(this.config.ajaxEndpoint, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        this.addPreviewToContainer(data.html);
                        this.updateFileInputOrder();
                    } else {
                        this.showError(data.message || this.getText(this.config.messages.loadPreviewError, 'Error'));
                    }
                })
                .catch(error => {
                    console.error('[MediaManager] Fetch error:', error);
                    this.showError(this.getText(this.config.messages.loadPreviewError, 'Error'));
                });
        },

        addPreviewToContainer(html) {
            const container = document.querySelector(this.config.gridSelector);
            if (!container) return;

            const placeholder = container.querySelector(this.config.placeholderSelector);
            if (placeholder) placeholder.remove();

            container.insertAdjacentHTML('afterbegin', html);
        },

        updateFileInputOrder() {
            const container   = document.querySelector(this.config.gridSelector);
            const hiddenInput = document.querySelector(this.config.fileInputSelector);

            if (!container || !hiddenInput) return;

            const dataTransfer = new DataTransfer();
            const cards = container.querySelectorAll(this.config.cardSelector);

            cards.forEach(card => {
                if (card.style.display === 'none') return;

                const sourceInput = card.querySelector(this.config.sourceInputSelector);
                if (!sourceInput) return;

                const source = sourceInput.value;

                if (source.startsWith('blob:')) {
                    const file = this.fileMap.get(source);
                    if (file) dataTransfer.items.add(file);
                }
            });

            hiddenInput.files = dataTransfer.files;

            if (window.MediaManagerDebug) {
                console.log('[MediaManager] File input synced. Total files:', hiddenInput.files.length);
            }
        },

        updatePlaceholder() {
            const container = document.querySelector(this.config.gridSelector);
            if (!container) return;

            const visibleCards = container.querySelectorAll(
                this.config.cardSelector + ':not([style*="display: none"])'
            );
            const placeholder = container.querySelector(this.config.placeholderSelector);

            if (visibleCards.length === 0 && !placeholder) {
                const div = document.createElement('div');
                div.className = this.config.placeholderSelector.replace('.', '');
                div.innerHTML = '<p>' + this.getText(this.config.messages.placeholderText, 'No items') + '</p>';
                container.appendChild(div);
            } else if (visibleCards.length > 0 && placeholder) {
                placeholder.remove();
            }
        },

        // =====================================================================
        //  CLEANUP
        // =====================================================================

        destroy() {
            if (this.sortableInstance) {
                this.sortableInstance.destroy();
                this.sortableInstance = null;
            }
            this.fileMap.forEach((file, key) => {
                if (key.startsWith('blob:')) URL.revokeObjectURL(key);
            });
            this.fileMap.clear();
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => MediaManager.init());
    } else {
        MediaManager.init();
    }

    window.MediaManager = MediaManager;
})();