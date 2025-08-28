/**
 * Table Sorting Library
 * Provides sortable functionality for tables with various data types
 */
class TableSort {
    constructor(tableSelector, options = {}) {
        this.table = document.querySelector(tableSelector);
        if (!this.table) {
            console.error('Table not found:', tableSelector);
            return;
        }
        
        this.options = {
            excludeColumns: [], // Array of column indices to exclude from sorting
            customSorters: {}, // Custom sorting functions for specific columns
            dateFormat: 'auto', // 'auto', 'us', 'iso', or custom function
            numberFormat: 'auto', // 'auto' or custom function
            ...options
        };
        
        this.currentSort = {
            column: -1,
            direction: 'asc'
        };
        
        this.init();
    }
    
    init() {
        this.table.classList.add('sortable-table');
        this.addSortingHeaders();
        this.bindEvents();
    }
    
    addSortingHeaders() {
        const headers = this.table.querySelectorAll('thead th');
        headers.forEach((header, index) => {
            if (!this.options.excludeColumns.includes(index)) {
                header.classList.add('sortable');
                header.setAttribute('data-column', index);
                
                // Add sort icon
                const sortIcon = document.createElement('span');
                sortIcon.className = 'sort-icon';
                header.appendChild(sortIcon);
            }
        });
    }
    
    bindEvents() {
        this.table.addEventListener('click', (e) => {
            const header = e.target.closest('th.sortable');
            if (header) {
                const column = parseInt(header.getAttribute('data-column'));
                this.sortTable(column);
            }
        });
    }
    
    sortTable(columnIndex) {
        const tbody = this.table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        // Determine sort direction
        let direction = 'asc';
        if (this.currentSort.column === columnIndex && this.currentSort.direction === 'asc') {
            direction = 'desc';
        }
        
        // Update visual indicators
        this.updateSortHeaders(columnIndex, direction);
        
        // Sort rows
        const sortedRows = this.sortRows(rows, columnIndex, direction);
        
        // Update table
        tbody.innerHTML = '';
        sortedRows.forEach(row => tbody.appendChild(row));
        
        // Update current sort state
        this.currentSort = { column: columnIndex, direction };
        
        // Trigger custom event
        this.table.dispatchEvent(new CustomEvent('tableSorted', {
            detail: { column: columnIndex, direction, rows: sortedRows }
        }));
    }
    
    updateSortHeaders(activeColumn, direction) {
        const headers = this.table.querySelectorAll('thead th');
        headers.forEach((header, index) => {
            header.classList.remove('sort-asc', 'sort-desc');
            if (index === activeColumn) {
                header.classList.add(`sort-${direction}`);
            }
        });
    }
    
    sortRows(rows, columnIndex, direction) {
        return rows.sort((a, b) => {
            const aCell = a.children[columnIndex];
            const bCell = b.children[columnIndex];
            
            if (!aCell || !bCell) return 0;
            
            let aValue = this.getCellValue(aCell, columnIndex);
            let bValue = this.getCellValue(bCell, columnIndex);
            
            // Use custom sorter if available
            if (this.options.customSorters[columnIndex]) {
                return this.options.customSorters[columnIndex](aValue, bValue, direction);
            }
            
            // Determine data type and sort accordingly
            const result = this.compareValues(aValue, bValue);
            return direction === 'asc' ? result : -result;
        });
    }
    
    getCellValue(cell, columnIndex) {
        // Try to get value from data attribute first
        if (cell.hasAttribute('data-sort')) {
            return cell.getAttribute('data-sort');
        }
        
        // Get text content, removing extra whitespace
        let text = cell.textContent || cell.innerText || '';
        return text.trim();
    }
    
    compareValues(a, b) {
        // Handle empty values
        if (!a && !b) return 0;
        if (!a) return 1;
        if (!b) return -1;
        
        // Try numeric comparison first
        const numA = this.parseNumber(a);
        const numB = this.parseNumber(b);
        
        if (!isNaN(numA) && !isNaN(numB)) {
            return numA - numB;
        }
        
        // Try date comparison
        const dateA = this.parseDate(a);
        const dateB = this.parseDate(b);
        
        if (dateA && dateB) {
            return dateA.getTime() - dateB.getTime();
        }
        
        // Default to string comparison (case-insensitive)
        return a.toLowerCase().localeCompare(b.toLowerCase());
    }
    
    parseNumber(value) {
        // Remove common non-numeric characters but preserve decimal points and negative signs
        const cleaned = value.toString().replace(/[,$\s%]/g, '');
        const num = parseFloat(cleaned);
        return isNaN(num) ? NaN : num;
    }
    
    parseDate(value) {
        // Common date patterns
        const datePatterns = [
            /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/, // MM/DD/YYYY or M/D/YYYY
            /^(\d{4})-(\d{1,2})-(\d{1,2})$/, // YYYY-MM-DD
            /^(\w{3})\s+(\d{1,2}),\s+(\d{4})$/, // Mon DD, YYYY
            /^(\d{1,2})\s+(\w{3})\s+(\d{4})$/, // DD Mon YYYY
        ];
        
        // Try parsing with JavaScript Date constructor
        const jsDate = new Date(value);
        if (!isNaN(jsDate.getTime())) {
            return jsDate;
        }
        
        // Try manual parsing for specific formats
        for (const pattern of datePatterns) {
            const match = value.match(pattern);
            if (match) {
                try {
                    // Handle different date formats
                    if (pattern.source.includes('\\w{3}')) {
                        // Month name format
                        return new Date(value);
                    } else {
                        // Numeric format
                        const [, p1, p2, p3] = match;
                        if (pattern.source.includes('\\d{4}-')) {
                            // YYYY-MM-DD
                            return new Date(parseInt(p1), parseInt(p2) - 1, parseInt(p3));
                        } else {
                            // MM/DD/YYYY
                            return new Date(parseInt(p3), parseInt(p1) - 1, parseInt(p2));
                        }
                    }
                } catch (e) {
                    continue;
                }
            }
        }
        
        return null;
    }
    
    // Public methods for manual control
    sortByColumn(columnIndex, direction = 'asc') {
        this.sortTable(columnIndex);
        if (this.currentSort.direction !== direction) {
            this.sortTable(columnIndex); // Sort again to get desired direction
        }
    }
    
    getCurrentSort() {
        return { ...this.currentSort };
    }
    
    destroy() {
        // Clean up event listeners and classes
        this.table.classList.remove('sortable-table');
        const headers = this.table.querySelectorAll('thead th');
        headers.forEach(header => {
            header.classList.remove('sortable', 'sort-asc', 'sort-desc');
            header.removeAttribute('data-column');
            const sortIcon = header.querySelector('.sort-icon');
            if (sortIcon) {
                sortIcon.remove();
            }
        });
    }
}

// Auto-initialize tables with sortable class
document.addEventListener('DOMContentLoaded', function() {
    const sortableTables = document.querySelectorAll('table.auto-sort');
    sortableTables.forEach(table => {
        new TableSort(`#${table.id}`, {
            excludeColumns: JSON.parse(table.dataset.excludeColumns || '[]')
        });
    });
});

// Utility function for easy initialization
function initTableSort(selector, options = {}) {
    return new TableSort(selector, options);
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = TableSort;
}

// Global access
window.TableSort = TableSort;
window.initTableSort = initTableSort;
