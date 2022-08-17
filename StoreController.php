<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ExportStoreFormType;
use App\Form\ImportStoreFormType;
use App\Form\RegistrationFormType;
use Cassandra\Type\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

#[Route('/stores')]
class StoreController extends AbstractController
{

    private $userPasswordHasherInterface;
    private $entityManager;

    public function __construct (UserPasswordHasherInterface $userPasswordHasherInterface, EntityManagerInterface $entityManager)
    {
        $this->userPasswordHasherInterface = $userPasswordHasherInterface;
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'app_store')]
    public function index(): Response
    {
        return $this->render('store/index.html.twig', [

            'controller_name' => 'StoreController',
        ]);
    }

    #[Route('/register-stores', name: 'register')]
    public function registerStores(Request $request): Response
    {
        $user = new User();
//        $store = new Store();

        // Create Store User
        $storeUserForm = $this->createForm(RegistrationFormType::class, $user, [
            'action' => $this->generateUrl('create_user'),
            'method' => 'POST',
        ]);

        // Import CSV Form
        $csvImportForm = $this->createForm(ImportStoreFormType::class, $user, [
            'action' => $this->generateUrl('import_csv'),
            'method' => 'POST',
        ]);

        // Export CSV Form
        $csvExportForm = $this->createForm(ExportStoreFormType::class, $user, [
            'action' => $this->generateUrl('export_csv'),
            'method' => 'POST',
        ]);


        return $this->render('store/register_stores.html.twig', [
            'storeUserForm' => $storeUserForm->createView(),
            'importCsvForm' => $csvImportForm->createView(),
            'exportCsvForm' => $csvExportForm->createView(),
        ]);
    }


    #[Route('/listing', name: 'listing')]
    public function storeListing(Request $request){

        $stores = $this->entityManager->getRepository(User::class)->getActiveUser();
        return $this->render('store/listing.html.twig', [
            'stores'=>$stores
        ]);
    }

    #[Route('/csv', name: 'import_csv')]
    public function importCsv(Request $request): Response
    {

        $file = $request->files->get('import_store_form')['csv_file'];
        $decoder = new Serializer([new ObjectNormalizer()],[new CsvEncoder()]);
        $csvRows = $decoder->decode(file_get_contents($file),'csv');
        $userRepo = $this->entityManager->getRepository(User::class);

        foreach ($csvRows as $key=>$row){

            if($row['Username'] != null) {
                $ifUserExist =  $this->entityManager->getRepository(User::class)->findBy(['email'=>$row['Email']]);
                if(!$ifUserExist){
                    $user = new User();
                    $user->setUsername($row['Username']);
                    $user->setEmail($row['Email']);
                    $user->setAddress($row['Address']);
                    $user->setCity($row['City']);
                    $user->setState($row['State']);
                    $user->setZipCode($row['ZipCode']);
                    $user->setPhone($row['Phone']);
                    $user->setRoles(array($row['Phone']));
                    $user->setDisableLogin(false);
                    $user->setPassword(
                        $this->userPasswordHasherInterface->hashPassword(
                            $user,
                            '123'
                        ));
                    $user->setUpdatedAt((new \DateTime('now')));
                    $user->setCreatedAt((new \DateTime('now')));

                    $this->entityManager->persist($user);
                }
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success','CSV import Succesfully');
        return $this->redirectToRoute('register');
    }

    #[Route('/export', name: 'export_csv')]
    public function exportCsv(Request $request){

        $date = date('d-m-y');
        $allRecord = $this->entityManager->getRepository(User::class)->exportStoreCsv();

        $encoders = [new CsvEncoder()];
        $normalizers = array(new ObjectNormalizer());
        $serializer = new Serializer($normalizers, $encoders);
        $csvContent = $serializer->serialize($allRecord, 'csv');

        $response = new Response($csvContent);
        $response->headers->set('Content-Encoding', 'UTF-8');
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename='.$date.'_Stores.csv');
        return $response;
    }

    #[Route('/create-user', name: 'create_user')]
    public function createStoreUser(Request $request){

        $username = $request->get('registration_form')['username'];
        $email = $request->get('registration_form')['email'];
        $password = $request->get('registration_form')['password'];
        $phone = $request->get('registration_form')['phone'];
        $roles = $request->get('registration_form')['roles'];
        $address = $request->get('registration_form')['address'];
        $state = $request->get('registration_form')['state'];
        $city = $request->get('registration_form')['city'];
        $zipcode = $request->get('registration_form')['zipcode'];

        $user = new User();

        $isEmailExist = $this->entityManager->getRepository(User::class)->findBy(['email'=>$email]);
        if($isEmailExist){
            $this->addFlash('danger', 'Email already exist');
            return $this->redirectToRoute('register');
        }else{
            $user->setUsername($username);
            $user->setEmail($email);
            $user->setPhone($phone);
            $user->setRoles([$roles]);
            $user->setAddress($address);
            $user->setState($address);
            $user->setAddress($state);
            $user->setCity($city);
            $user->setZipcode($zipcode);
            $user->setDisableLogin(false);
            $user->setPassword(
                $this->userPasswordHasherInterface->hashPassword(
                    $user,
                    $password
                )
            );
            $user->setUpdatedAt((new \DateTime('now')));
            $user->setCreatedAt((new \DateTime('now')));

            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        $this->addFlash('success', 'New User sucessfully added');

        return $this->redirectToRoute('register');
    }

    #[Route('/delete-user/{id}', name: 'delete_user')]
    public function removeUserAction(string $id)
    {
        try {
            $user =  $this->entityManager->getRepository(User::class)->find($id);
            $user->setDisableLogin(true);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return new JsonResponse(array('message'=>'success'));
        }catch (\Exception $exception){
            return new JsonResponse(array('message'=>$exception));
        }

    }

    #[Route('/edit-user/{id}', name: 'edit_user')]
    public function editStore(Request $request){
        $id = $request->get('id');
        $user =  $this->entityManager->getRepository(User::class)->find($id);
        if(!$user){
            throw $this->notFoundException();
        }
        $form =   $this->createForm(RegistrationFormType::class,$user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $updatedData = $form->getData();
            $updatedData->setDisableLogin(false);
            $this->entityManager->persist($updatedData);
            $this->entityManager->flush();

            return $this->redirectToRoute('listing');
        }
        return $this->renderForm('store/editStore.html.twig', [
            'userEditForm' => $form,
        ]);

    }
}
