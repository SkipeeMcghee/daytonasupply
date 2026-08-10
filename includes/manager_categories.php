<?php if (empty($_SESSION['admin'])) return; ?>
<section id="categories" class="manager-categories">
    <div class="category-manager-heading">
        <div>
            <h3>Categories</h3>
            <p class="category-manager-summary"><?= count($categoryStatus['unassigned']) ?> unassigned SKUs · <?= count($categoryStatus['stale']) ?> stale assignments</p>
        </div>
        <div class="category-manager-heading-actions">
            <button type="button" class="mgr-btn mgr-delete" id="categoryRestoreBaseline">Restore Baseline</button>
            <button type="button" class="proceed-btn" id="categoryAdd">Add Category</button>
        </div>
    </div>
    <div id="categoryNotice" class="category-manager-notice" role="status"<?= $inventoryCategoryNotice === '' ? ' hidden' : '' ?>><?= htmlspecialchars($inventoryCategoryNotice) ?></div>
    <div class="category-manager-layout">
        <aside class="category-manager-tree" aria-label="Category groups and categories">
            <form id="categoryGroupAdd" class="category-group-add">
                <input type="text" name="name" maxlength="128" placeholder="New group name" required>
                <button type="submit" class="mgr-btn mgr-verify">Add Group</button>
            </form>
            <div id="categoryTree"></div>
        </aside>
        <div class="category-manager-detail">
            <form id="categoryForm">
                <input type="hidden" id="categoryId">
                <div class="category-editor-grid">
                    <label>Name<input type="text" id="categoryName" maxlength="128" required></label>
                    <label>Group<select id="categoryGroup" required></select></label>
                    <label>Parent category<select id="categoryParent"><option value="">None (top level)</option></select></label>
                    <label class="category-check"><input type="checkbox" id="categoryActive" checked> Active</label>
                    <label class="category-check"><input type="checkbox" id="categoryFeatured"> Show on homepage</label>
                </div>
                <div class="category-editor-actions">
                    <button type="submit" class="proceed-btn">Save Category</button>
                    <button type="button" class="mgr-btn mgr-delete" id="categoryDelete" hidden>Delete</button>
                </div>
            </form>

            <div class="category-assignment-editor" id="categoryAssignments" hidden>
                <div class="category-assignment-head">
                    <div><h4>SKU Assignment</h4><span id="categoryAssignmentCount"></span></div>
                    <button type="button" class="proceed-btn" id="categoryAssignmentsSave">Save Assignments</button>
                </div>
                <div class="category-assignment-filters">
                    <input type="search" id="categorySkuSearch" placeholder="Search SKU or description">
                    <select id="categorySkuView" aria-label="Assignment view">
                        <option value="all">All inventory</option>
                        <option value="assigned">Assigned here</option>
                        <option value="unassigned">Unassigned anywhere</option>
                    </select>
                </div>
                <div id="categorySkuList" class="category-sku-list"></div>
            </div>
        </div>
    </div>
</section>

<script type="application/json" id="categoryManagerData"><?php echo json_encode($categoryManagerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script>
(function(){
    var source = document.getElementById('categoryManagerData');
    if (!source) return;
    var data = JSON.parse(source.textContent || '{}');
    var selectedId = 0;
    var flat = [];
    var categoryPlaceholder = '/assets/images/DaytonaSupplyDSlogo.png';
    var unassigned = new Set(data.unassigned || []);
    (data.groups || []).forEach(function(group){
        (group.categories || []).forEach(function(category){
            flat.push(category);
            (category.children || []).forEach(function(child){ flat.push(child); });
        });
    });
    function byId(id){ return flat.find(function(category){ return Number(category.id) === Number(id); }); }
    function escapeHtml(value){ var node = document.createElement('div'); node.textContent = String(value || ''); return node.innerHTML; }
    function escapeAttribute(value){ return escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function notice(message, error){
        var box = document.getElementById('categoryNotice');
        box.hidden = false;
        box.textContent = message;
        box.classList.toggle('is-error', !!error);
    }
    function post(action, values){
        var body = new FormData();
        body.append('action', action);
        body.append('csrf_token', data.csrf || '');
        Object.keys(values || {}).forEach(function(key){ body.append(key, values[key]); });
        return fetch('/ajax/manage_categories.php', {method:'POST', body:body, credentials:'same-origin'})
            .then(function(response){ return response.json().catch(function(){ return {}; }).then(function(json){ if (!response.ok || !json.success) throw new Error(json.error || 'Request failed.'); return json; }); });
    }
    function reload(id){
        if (id) localStorage.setItem('managerCategoryId', String(id));
        window.location.hash = 'categories';
        window.location.reload();
    }
    function categoryRow(category, child){
        var row = document.createElement('div');
        row.className = 'category-tree-row' + (child ? ' is-child' : '') + (Number(category.id) === selectedId ? ' is-selected' : '');
        row.dataset.categoryId = category.id;
        var hasOwnImage = String(category.image_path || '') !== '';
        var imageState = hasOwnImage ? 'Custom' : (category.parent_id ? 'Inherited' : 'Default');
        var imageUrl = category.image_url || categoryPlaceholder;
        row.innerHTML = '<div class="category-row-image"><div class="manager-dropzone category-image-dropzone" data-category-image-dropzone title="Drop image here or click to upload"><div class="dz-preview"><img src="' + escapeAttribute(imageUrl) + '" alt="' + escapeAttribute(category.name) + ' image"></div><div class="dz-instructions">Drop or click</div><input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="dz-file"></div><div class="category-image-row-controls"><small data-category-image-state>' + imageState + '</small><button type="button" class="mgr-btn mgr-delete" data-category-image-remove' + (hasOwnImage ? '' : ' disabled') + '>Remove</button></div></div><button type="button" class="category-tree-select"><span>' + escapeHtml(category.name) + '</span><small>' + Number(category.direct_product_count || 0) + '</small></button><span class="category-tree-actions"><button type="button" title="Move up" data-category-move="up">↑</button><button type="button" title="Move down" data-category-move="down">↓</button></span>';
        return row;
    }
    function fallbackImage(category){
        var parent = category && category.parent_id ? byId(category.parent_id) : null;
        return parent && parent.image_url ? parent.image_url : categoryPlaceholder;
    }
    function refreshCategoryImageRow(category){
        var row = document.querySelector('.category-tree-row[data-category-id="' + Number(category.id) + '"]');
        if (!row) return;
        var hasOwnImage = String(category.image_path || '') !== '';
        var image = row.querySelector('.category-image-dropzone img');
        var remove = row.querySelector('[data-category-image-remove]');
        var state = row.querySelector('[data-category-image-state]');
        if (image) image.src = category.image_url || categoryPlaceholder;
        if (remove) remove.disabled = !hasOwnImage;
        if (state) state.textContent = hasOwnImage ? 'Custom' : (category.parent_id ? 'Inherited' : 'Default');
    }
    function refreshInheritedChildren(category){
        flat.forEach(function(child){
            if (Number(child.parent_id) !== Number(category.id) || String(child.image_path || '') !== '') return;
            child.image_url = category.image_url || categoryPlaceholder;
            refreshCategoryImageRow(child);
        });
    }
    function uploadCategoryImage(category, file, dropzone){
        if (!category || !file) return;
        var body = new FormData();
        body.append('category_id', category.id);
        body.append('csrf_token', data.csrf || '');
        body.append('image', file);
        dropzone.classList.add('dz-uploading');
        fetch('/ajax/upload_category_image.php', {method:'POST', body:body, credentials:'same-origin'})
            .then(function(response){ return response.json().catch(function(){ return {}; }).then(function(json){ if (!response.ok || !json.success) throw new Error(json.error || 'Upload failed.'); return json; }); })
            .then(function(json){
                category.image_path = 'category-upload:';
                category.image_url = json.url;
                refreshCategoryImageRow(category);
                refreshInheritedChildren(category);
                notice('Category image saved.', false);
            })
            .catch(function(error){ notice(error.message, true); })
            .finally(function(){ dropzone.classList.remove('dz-uploading'); var input = dropzone.querySelector('.dz-file'); if (input) input.value = ''; });
    }
    function removeCategoryImage(category, dropzone){
        var body = new FormData();
        body.append('category_id', category.id);
        body.append('csrf_token', data.csrf || '');
        dropzone.classList.add('dz-uploading');
        fetch('/ajax/remove_category_image.php', {method:'POST', body:body, credentials:'same-origin'})
            .then(function(response){ return response.json().catch(function(){ return {}; }).then(function(json){ if (!response.ok || !json.success) throw new Error(json.error || 'Remove failed.'); }); })
            .then(function(){
                category.image_path = null;
                category.image_url = fallbackImage(category);
                refreshCategoryImageRow(category);
                refreshInheritedChildren(category);
                notice('Category image removed.', false);
            })
            .catch(function(error){ notice(error.message, true); })
            .finally(function(){ dropzone.classList.remove('dz-uploading'); });
    }
    function renderTree(){
        var root = document.getElementById('categoryTree');
        root.innerHTML = '';
        (data.groups || []).forEach(function(group){
            var block = document.createElement('section');
            block.className = 'category-tree-group';
            block.dataset.groupId = group.id;
            block.innerHTML = '<div class="category-tree-group-head"><input maxlength="128" value="' + escapeHtml(group.name) + '" aria-label="Group name"><span class="category-tree-actions"><button type="button" title="Move group up" data-group-move="up">↑</button><button type="button" title="Move group down" data-group-move="down">↓</button><button type="button" title="Save group" data-group-save>Save</button><button type="button" title="Delete empty group" data-group-delete>×</button></span></div>';
            var list = document.createElement('div');
            list.className = 'category-tree-list';
            (group.categories || []).forEach(function(category){
                list.appendChild(categoryRow(category, false));
                (category.children || []).forEach(function(child){ list.appendChild(categoryRow(child, true)); });
            });
            block.appendChild(list);
            root.appendChild(block);
        });
    }
    function fillSelects(category){
        var groupSelect = document.getElementById('categoryGroup');
        var parentSelect = document.getElementById('categoryParent');
        groupSelect.innerHTML = '';
        parentSelect.innerHTML = '<option value="">None (top level)</option>';
        (data.groups || []).forEach(function(group){
            groupSelect.add(new Option(group.name, group.id));
            (group.categories || []).forEach(function(parent){
                if (!category || Number(parent.id) !== Number(category.id)) parentSelect.add(new Option(group.name + ' / ' + parent.name, parent.id));
            });
        });
    }
    function renderSkuList(){
        var query = document.getElementById('categorySkuSearch').value.toLowerCase();
        var view = document.getElementById('categorySkuView').value;
        var assigned = new Set((data.assignments || {})[selectedId] || []);
        var list = document.getElementById('categorySkuList');
        list.innerHTML = '';
        var shown = 0;
        (data.products || []).forEach(function(product){
            var isAssigned = assigned.has(product.name);
            if (view === 'assigned' && !isAssigned) return;
            if (view === 'unassigned' && !unassigned.has(product.name)) return;
            if (query && (product.name + ' ' + product.description).toLowerCase().indexOf(query) === -1) return;
            var label = document.createElement('label');
            label.className = 'category-sku-row';
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.value = product.name; checkbox.checked = isAssigned;
            var text = document.createElement('span');
            var strong = document.createElement('strong'); strong.textContent = product.name;
            var small = document.createElement('small'); small.textContent = product.description;
            text.appendChild(strong); text.appendChild(small); label.appendChild(checkbox); label.appendChild(text); list.appendChild(label);
            shown++;
        });
        document.getElementById('categoryAssignmentCount').textContent = assigned.size + ' assigned · ' + shown + ' shown';
    }
    function selectCategory(id){
        selectedId = Number(id) || 0;
        var category = byId(selectedId);
        fillSelects(category);
        document.getElementById('categoryId').value = category ? category.id : '';
        document.getElementById('categoryName').value = category ? category.name : '';
        document.getElementById('categoryGroup').value = category ? category.group_id : ((data.groups[0] || {}).id || '');
        document.getElementById('categoryParent').value = category && category.parent_id ? category.parent_id : '';
        document.getElementById('categoryActive').checked = !category || Number(category.active) === 1;
        document.getElementById('categoryFeatured').checked = !!category && Number(category.featured_homepage) === 1;
        document.getElementById('categoryFeatured').disabled = !!category && !!category.parent_id;
        document.getElementById('categoryDelete').hidden = !category;
        document.getElementById('categoryAssignments').hidden = !category;
        if (category) {
            localStorage.setItem('managerCategoryId', String(category.id));
            renderSkuList();
        }
        renderTree();
    }
    document.getElementById('categoryTree').addEventListener('click', function(event){
        var groupBlock = event.target.closest('.category-tree-group');
        var row = event.target.closest('.category-tree-row');
        var dropzone = event.target.closest('[data-category-image-dropzone]');
        if (event.target.closest('[data-category-image-remove]') && row) {
            var removeCategory = byId(row.dataset.categoryId);
            if (removeCategory && confirm('Remove this category image?')) removeCategoryImage(removeCategory, row.querySelector('[data-category-image-dropzone]'));
            return;
        }
        if (dropzone && row) {
            if (!event.target.closest('.dz-file')) dropzone.querySelector('.dz-file').click();
            return;
        }
        if (event.target.closest('.category-tree-select') && row) return selectCategory(row.dataset.categoryId);
        var move = event.target.getAttribute('data-category-move');
        if (move && row) post('reorder_category', {id:row.dataset.categoryId, direction:move}).then(function(){ reload(row.dataset.categoryId); }).catch(function(error){ notice(error.message, true); });
        var groupMove = event.target.getAttribute('data-group-move');
        if (groupMove && groupBlock) post('reorder_group', {id:groupBlock.dataset.groupId, direction:groupMove}).then(function(){ reload(selectedId); }).catch(function(error){ notice(error.message, true); });
        if (event.target.hasAttribute('data-group-save') && groupBlock) post('save_group', {id:groupBlock.dataset.groupId, name:groupBlock.querySelector('input').value, active:1}).then(function(){ reload(selectedId); }).catch(function(error){ notice(error.message, true); });
        if (event.target.hasAttribute('data-group-delete') && groupBlock && confirm('Delete this empty group?')) post('delete_group', {id:groupBlock.dataset.groupId}).then(function(){ reload(selectedId); }).catch(function(error){ notice(error.message, true); });
    });
    document.getElementById('categoryTree').addEventListener('change', function(event){
        if (!event.target.matches('.category-image-dropzone .dz-file') || !event.target.files[0]) return;
        var row = event.target.closest('.category-tree-row');
        uploadCategoryImage(byId(row.dataset.categoryId), event.target.files[0], event.target.closest('[data-category-image-dropzone]'));
    });
    document.getElementById('categoryTree').addEventListener('dragover', function(event){
        var dropzone = event.target.closest('[data-category-image-dropzone]');
        if (!dropzone) return;
        event.preventDefault();
        dropzone.classList.add('dz-over');
    });
    document.getElementById('categoryTree').addEventListener('dragleave', function(event){
        var dropzone = event.target.closest('[data-category-image-dropzone]');
        if (dropzone) dropzone.classList.remove('dz-over');
    });
    document.getElementById('categoryTree').addEventListener('drop', function(event){
        var dropzone = event.target.closest('[data-category-image-dropzone]');
        if (!dropzone) return;
        event.preventDefault();
        dropzone.classList.remove('dz-over');
        var row = dropzone.closest('.category-tree-row');
        var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
        if (file) uploadCategoryImage(byId(row.dataset.categoryId), file, dropzone);
    });
    document.getElementById('categoryGroupAdd').addEventListener('submit', function(event){ event.preventDefault(); post('save_group', {name:this.elements.name.value, active:1}).then(function(){ reload(selectedId); }).catch(function(error){ notice(error.message, true); }); });
    document.getElementById('categoryAdd').addEventListener('click', function(){ selectCategory(0); document.getElementById('categoryName').focus(); });
    document.getElementById('categoryRestoreBaseline').addEventListener('click', function(){
        var confirmation = prompt('This permanently replaces every category, subcategory, image selection, order, and SKU assignment with baseline version 1. Products are not deleted.\n\nType RESET CATEGORIES to continue.');
        if (confirmation === null) return;
        post('restore_baseline', {confirmation:confirmation}).then(function(json){
            localStorage.removeItem('managerCategoryId');
            var summary = json.summary || {};
            alert('Baseline restored: ' + Number(summary.categories || 0) + ' categories and ' + Number(summary.assignments || 0) + ' assignments.');
            reload(0);
        }).catch(function(error){ notice(error.message, true); });
    });
    document.getElementById('categoryForm').addEventListener('submit', function(event){
        event.preventDefault();
        post('save_category', {id:document.getElementById('categoryId').value, name:document.getElementById('categoryName').value, group_id:document.getElementById('categoryGroup').value, parent_id:document.getElementById('categoryParent').value, active:document.getElementById('categoryActive').checked ? 1 : 0, featured_homepage:document.getElementById('categoryFeatured').checked ? 1 : 0})
            .then(function(json){ reload(json.id || selectedId); }).catch(function(error){ notice(error.message, true); });
    });
    document.getElementById('categoryDelete').addEventListener('click', function(){ if (selectedId && confirm('Delete this category, its subcategories, and all category assignments? Products will not be deleted.')) post('delete_category', {id:selectedId}).then(function(){ localStorage.removeItem('managerCategoryId'); reload(0); }).catch(function(error){ notice(error.message, true); }); });
    document.getElementById('categorySkuSearch').addEventListener('input', renderSkuList);
    document.getElementById('categorySkuView').addEventListener('change', renderSkuList);
    document.getElementById('categoryAssignmentsSave').addEventListener('click', function(){
        var skus = Array.prototype.map.call(document.querySelectorAll('#categorySkuList input:checked'), function(input){ return input.value; });
        if (document.getElementById('categorySkuView').value !== 'all' || document.getElementById('categorySkuSearch').value) {
            var visible = new Set(Array.prototype.map.call(document.querySelectorAll('#categorySkuList input'), function(input){ return input.value; }));
            ((data.assignments || {})[selectedId] || []).forEach(function(sku){ if (!visible.has(sku)) skus.push(sku); });
        }
        post('replace_assignments', {id:selectedId, skus:JSON.stringify(skus)}).then(function(){ reload(selectedId); }).catch(function(error){ notice(error.message, true); });
    });
    document.getElementById('categoryParent').addEventListener('change', function(){ document.getElementById('categoryFeatured').disabled = !!this.value; if (this.value) document.getElementById('categoryFeatured').checked = false; });
    var initial = Number(localStorage.getItem('managerCategoryId') || 0);
    selectCategory(byId(initial) ? initial : ((flat[0] || {}).id || 0));
})();
</script>