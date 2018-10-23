<?php

namespace AdminBundle\Controller;

use AppBundle\Entity\Translation;
use CatalogBundle\Entity\Category;
use CatalogBundle\Entity\CategoryBanner;
use CatalogBundle\Entity\CategoryProduct;
use CatalogBundle\Form\CategoryType;
use MediaBundle\Entity\Image;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class CategoryController extends Controller {

    const BATCH = 50;

    public function indexAction($type = "normal") {

        $em = $this->getDoctrine()->getManager();
        $type = strtoupper($type);

        $categories = $em->getRepository('CatalogBundle:Category')->findBy(array(
            'type' => $type,
            'isEnabled' => true,
        ));
        return $this->render('AdminBundle:Categories:index.html.twig', array(
            'categories' => $categories
        ));
    }

    public function viewAction($id) {
        
        $em = $this->getDoctrine()->getManager();
        
        $category = $em->getRepository('CatalogBundle:Category')->find($id);
        $langs = $em->getRepository('AppBundle:Language')->findAll();

        $form = $this->createForm(CategoryType::class, $category, array(
            'method' => "POST",
            'action' => $this->generateUrl('admin_categories_edit', array('id' => $category->getId()))
        ));

        return $this->render('AdminBundle:Categories:view.html.twig', array(
            'category' => $category,
            'langs' => $langs,
            'form' => $form->createView(),
        ));
    }

    public function addAction(Request $request) {
        $em = $this->getDoctrine()->getManager();

        $category = new Category();

        $form = $this->createForm(CategoryType::class, $category, array(
            'method' => "POST",
            'action' => $this->generateUrl('admin_categories_add')
        ));

        if ($request->isMethod("POST")) {

            $form->handleRequest($request);

            if ($form->isValid()) {

                $em->persist($category);
                $em->flush();

                return $this->redirect($this->generateUrl('admin_categories_view', array('id' => $category->getId())));
            }
        }

        return $this->render('AdminBundle:Categories:add.html.twig', array(
            'form' => $form->createView()
        ));
    }

    public function editAction(Request $request, $id) {

        $em = $this->getDoctrine()->getManager();

        $category = $em->getRepository('CatalogBundle:Category')->find($id);

        if ($category) {

            $form = $this->createForm(CategoryType::class, $category, array(
                'method' => "POST",
                'action' => $this->generateUrl('admin_categories_edit', array('id' => $category->getId()))
            ));

            if ($request->isMethod("POST")) {

                $form->handleRequest($request);

                if ($form->isValid()) {

                    $em->merge($category);
                    $em->flush();

                    $this->get('session')->getFlashBag()->add('success', 'The category has been modified successfully');
                    return $this->redirect($this->generateUrl('admin_categories_view', array(
                        'id' => $category->getId()
                    )));
                }
            }

            return $this->render('AdminBundle:Categories:edit.html.twig', array(
                'form' => $form->createView()
            ));
        }

        return $this->redirect($this->generateUrl('admin_categories_homepage'));
    }

    public function saveNewSortAction(Request $request, $id) {

        $em = $this->getDoctrine()->getManager();
        $r = new JsonResponse();
        $rData = array();

        $category = $em->getRepository('CatalogBundle:Category')->find($id);

        if ($category) {

            $queuedItems = 1;
            $items = $request->request->get('theData');
            if ($items) {

                foreach ($items as $i) {
                    $product = $em->getRepository('CatalogBundle:Product')->find($i['itemId']);

                    if ($product) {

                        $order = $em->getRepository('CatalogBundle:CategoryProduct')->findOneBy(array(
                            'product' => $product,
                            'category' => $category
                        ));

                        if ($order) {
                            $queuedItems++;
                            $order->setSortIndex($i['newSort']);
                            $em->merge($order);
                        }
                    }

                    if ($queuedItems % $this::BATCH == 0 && $queuedItems > 0) {

                        $em->flush();
                    }
                }

                $em->flush();
                $rData['rStatus'] = "OK";
            }
            else {
                $rData['rStatus'] = "ERR";
            }
        }

        $r->setData($rData);
        return $r;
    }

    public function saveChildrenSortAction(Request $request, $id) {

        $em = $this->getDoctrine()->getManager();
        $r = new JsonResponse();
        $rData = array();

        $category = $em->getRepository('CatalogBundle:Category')->find($id);

        $children = $category->getChildren();

        if ($request->isMethod("POST")) {

            $data = $request->request->get('theData');
            foreach ($data as $d) {

                $item = $em->getRepository('CatalogBundle:Category')->find($d['itemId']);

                if ($item && $children->contains($item)) {
                    $item->setSortOrder($d['newSort']);
                    $em->merge($item);
                }
            }

            $em->flush();
            $rData['rStatus'] = "OK";
        }

        $r->setData($rData);
        return $r;
    }

    public function saveTranslationsAction(Request $request, $id) {

        $em = $this->getDoctrine()->getManager();
        $r = new JsonResponse();
        $rData = array();

        $category = $em->getRepository('CatalogBundle:Category')->find($id);
        $data = $request->request->get('theData');
        $now = new \DateTime();
        
        foreach ($data as $d) {

            $lang = $em->getRepository('AppBundle:Language')->find($d['lang']);
            if ($lang) {
                $type = strtoupper($d['type']);
                $t = $category->getTranslationByLangAndType($lang->getCode(), $type);
                if ($t == false) {
                    $type = strtoupper($d['type']);
                    $t = new Translation();
                    $t->setAllowHtml(false);
                    $t->setType($type);
                    $t->setIsEnabled(true);
                    $t->setLang($lang->getCode());
                    $t->setLastModifiedAt($now);
                    $t->setContent($d['value']);
                    $em->persist($t);

                    $category->addTranslation($t);
                    $em->merge($category);

                } else {

                    $t->setLastModifiedAt($now);
                    $t->setContent($d['value']);
                    $em->merge($t);
                }

                $em->flush();

                $rData['rStatus'] = "OK";
            }
        }

        $r->setData($rData);
        return $r;
    }

    public function uploadBannerAction(Request $request, $id, $lang) {

        $em = $this->getDoctrine()->getManager();
        $r = new JsonResponse();
        $rData = array();
        $lang = strtoupper($lang);
        $country = $em->getRepository('AppBundle:Country')->findOneBy(array(
            'code' => $lang
        ));

        if ($request->isMethod("POST")) {

            $category = $em->getRepository('CatalogBundle:Category')->find($id);
            if ($category) {

                if ($country) {

                    $countryCode = $country->getCode();
                    $file = $request->files->get("file");

                    /** @var CategoryBanner $banner */
                    $banner = $category->getBanner($countryCode);
                    $theFile = $file;
                    if ($banner == false) {

                        $image = new Image();
                        $image->setType("banner");
                        $image->setEntityType("category");
                        $image->setName($category->getId() . "_banner_" . $countryCode);
                        $image->setFile($theFile);
                        $em->persist($image);

                        $banner = new CategoryBanner();
                        $banner->setImage($image);
                        $banner->setCategory($category);
                        $banner->setCreatedAt(new \DateTime());
                        $banner->setLang($countryCode);
                        $em->persist($banner);

                    }
                    else {

                        $newImage = new Image();
                        $newImage->setType("banner");
                        $newImage->setEntityType("category");
                        $newImage->setName($category->getId() . "_banner_" . $countryCode);
                        $newImage->setFile($theFile);
                        $em->persist($newImage);

                        $banner->setImage($newImage);
                    }

                    $em->merge($banner);
                    $em->merge($category);

                    $em->flush();

                    $rData['rStatus'] = "OK";
                }
            }
        }

        $this->get('session')->getFlashBag()->add('success', 'Images added successfully');

        $r->setData($rData);

        return $r;
    }

    public function addProductsAction($id, $cid = null) {

        $em = $this->getDoctrine()->getManager();

        $collection = $em->getRepository('CatalogBundle:Category')->find($id);
        $targetCategory = $em->getRepository('CatalogBundle:Category')->find($cid);
        $products = null;

        $categories = $em->getRepository('CatalogBundle:Category')->findBy(array(
            'isEnabled' => true,
            'type' => "NORMAL"
        ));

        if ($targetCategory) {

            $childrenTargetCategories = array();
            $childrenTargetCategories[] = $targetCategory;

            $products = $em->getRepository('CatalogBundle:Product')->findAllForCategoryIterate($childrenTargetCategories);
        }

        if ($collection) {

            return $this->render('AdminBundle:Categories:addproducts.html.twig', array(
                'collection' => $collection,
                'targetCategory' => $targetCategory,
                'targetProducts' => $products,
                'categories' => $categories
            ));
        }

        return $this->redirect($this->generateUrl('admin_homepage'));
    }

    public function toggleInCategoryAction($id, $pid) {

        $em = $this->getDoctrine()->getManager();
        $r = new JsonResponse();
        $rData = array();

        $category = $em->getRepository('CatalogBundle:Category')->find($id);

        $product = $em->getRepository('CatalogBundle:Product')->find($pid);

        if ($category) {

            if ($product) {

                $cp = $em->getRepository('CatalogBundle:CategoryProduct')->findOneBy(array(
                    'category' => $category,
                    'product' => $product
                ));

                if ($cp) {

                    $em->remove($cp);
                    $em->flush();
                    $em->getRepository('CatalogBundle:CategoryProduct')->reSortOnly($category->getId());
                    $rData['rStatus'] = "OK";
                    $rData['rAction'] = "removed";
                } else {

                    $newOrder = $em->getRepository('CatalogBundle:CategoryProduct')->reSort($category->getId());
                    $cp = new CategoryProduct();
                    $cp->setProduct($product);
                    $cp->setCategory($category);
                    $cp->setSortIndex($newOrder);
                    $em->persist($cp);
                    $em->flush();
                    $rData['rStatus'] = "OK";
                    $rData['rAction'] = "added";
                }
            } else {

                $rData['rStatus'] = "ERR";
            }
        }
        else {

            $rData['rStatus'] = "ERR";
        }

        $r->setData($rData);
        return $r;
    }
}
