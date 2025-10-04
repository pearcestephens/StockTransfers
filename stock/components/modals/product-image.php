<?php
/**
 * Product Image Modal Component
 * 
 * Modal for displaying product images in full size
 * Client-side managed - no server variables needed
 */
?>

<div id="productImageModal" 
     class="modal fade" 
     tabindex="-1" 
     role="dialog" 
     aria-labelledby="productImageModalLabel" 
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productImageModalLabel">Product Image</h5>
                <button type="button" 
                        class="close" 
                        data-dismiss="modal" 
                        aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-center">
                <img id="productImageModalImg" 
                     src="" 
                     alt="Product" 
                     class="img-fluid" 
                     style="max-height: 70vh;">
            </div>
        </div>
    </div>
</div>
