<template>
    <div class="cz-product-gallery">
        <div class="cz-preview order-sm-2">

            <template v-for="(photo, index) in photos">
                <div class="cz-preview-item"
                     :class="{active: (index===0)}"
                     :id="`product-${index}`">
                    <img class="cz-image-zoom mx-auto" :src="photo"
                         style="max-width:200px;"
                         :data-zoom="photo"
                         :alt="photo">
                    <div class="cz-image-zoom-pane"></div>
                </div>
            </template>

        </div>


        <div class="cz-thumblist order-sm-1">
            <template v-for="(photo, index) in photos">
                <a class="cz-thumblist-item active" :href="`#product-${index}`">
                    <img :src="photo" :alt="photo">
                </a>
            </template>
        </div>
    </div>
</template>
<script type="text/babel">
    export default {
        data() {
            return {}
        },
        props: {
            photos: Array,
        },
        mounted() {
            this.productGallery();
        },
        methods: {
            productGallery() {


                let gallery = document.querySelectorAll('.cz-product-gallery');
                if (gallery.length) {

                    for (let i = 0; i < gallery.length; i++) {

                        let thumbnails = gallery[i].querySelectorAll('.cz-thumblist-item:not(.video-item)'),
                            previews = gallery[i].querySelectorAll('.cz-preview-item'),
                            videos = gallery[i].querySelectorAll('.cz-thumblist-item.video-item');


                        for (let n = 0; n < thumbnails.length; n++) {
                            thumbnails[n].addEventListener('click', changePreview);
                        }

                        // Changer preview function
                        function changePreview(e) {
                            e.preventDefault();
                            for (let i = 0; i < thumbnails.length; i++) {
                                previews[i].classList.remove('active');
                                thumbnails[i].classList.remove('active');
                            }
                            this.classList.add('active');
                            gallery[i].querySelector(this.getAttribute('href')).classList.add('active');
                        }

                        // Video thumbnail - open video in lightbox
                        for (let m = 0; m < videos.length; m++) {
                            lightGallery(videos[m], {
                                selector: 'this',
                                download: false,
                                videojs: true,
                                youtubePlayerParams: {
                                    modestbranding: 1,
                                    showinfo: 0,
                                    rel: 0,
                                    controls: 0
                                },
                                vimeoPlayerParams: {
                                    byline: 0,
                                    portrait: 0,
                                    color: 'fe696a'
                                }
                            });
                        }
                    }
                }
            },
        }
    }
</script>
